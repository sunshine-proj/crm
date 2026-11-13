<?php

namespace App\Service\Dms;

use App\Entity\Document;
use App\Entity\DocumentAttachment;
use App\Entity\DocumentComment;
use App\Entity\DocumentDisposal;
use App\Entity\User;
use App\Repository\DocumentAttachmentRepository;
use App\Repository\DocumentDisposalRepository;
use App\Repository\DocumentRepository;
use App\Repository\DocumentVersionRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Retention and disposal. Registered documents are never deleted: once archived and past the type's retention
 * period (counted from the archive date) and not under legal hold, a registry clerk proposes disposal and a
 * different administrator approves it. Approval destroys the version and attachment files, the extracted text,
 * comments, summary, tags and named readers, and keeps the document row (number, title, type, dates), the version
 * and attachment metadata with SHA-256, the trail, and the disposal record with its manifest as the tombstone.
 *
 * Exceptions: DocumentAccessDenied (403), \InvalidArgumentException (400), \DomainException (409).
 */
final class DisposalService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuthorizationCheckerInterface $authorization,
        private readonly DocumentService $documents,
        private readonly DmsFileStorage $storage,
        private readonly DmsNotifier $notifier,
        private readonly DocumentRepository $documentRepository,
        private readonly DocumentDisposalRepository $disposalRepository,
        private readonly DocumentVersionRepository $versionRepository,
        private readonly DocumentAttachmentRepository $attachmentRepository,
        private readonly UserRepository $userRepository,
        #[Autowire(service: 'monolog.logger.audit')] private readonly LoggerInterface $auditLogger,
    ) {
    }

    /** @return list<Document> archived documents past retention, not held, with no open proposal */
    public function eligible(\DateTimeImmutable $today): array
    {
        return array_values(array_filter(
            $this->documentRepository->findArchivedWithRetention(),
            fn (Document $document): bool => $document->isDueForDisposal($today) && $this->disposalRepository->findOpenFor($document) === null,
        ));
    }

    public function propose(Document $document, User $actor, ?string $reason): DocumentDisposal
    {
        if (!$this->authorization->isGranted('ROLE_DMS_ADMIN')) {
            throw new DocumentAccessDenied('Only registry clerks can propose disposal.');
        }
        $now = new \DateTimeImmutable();
        $disposal = null;
        $this->entityManager->wrapInTransaction(function () use ($document, $actor, $reason, $now, &$disposal): void {
            $this->entityManager->lock($document, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($document);
            if ($this->disposalRepository->findOpenFor($document) !== null) {
                throw new \DomainException('A disposal is already proposed for this document.');
            }
            $disposal = new DocumentDisposal($document, $actor->getUserIdentifier(), (string) $reason, $now);
            $this->entityManager->persist($disposal);
            $this->entityManager->flush();
            $this->documents->record($document, $actor, 'disposal_proposed', ['disposal' => $disposal->getId(), 'retain_until' => $disposal->getRetainUntil()->format('Y-m-d')]);
            foreach ($this->administrators() as $admin) {
                if ($admin->getId() !== $actor->getId()) {
                    $this->notifier->disposal($admin, $document, sprintf('%s proposes disposing of %s "%s".', $actor->getUserIdentifier(), $document->getRegistrationNumber() ?? '', $document->getTitle()), sprintf('disposal:%d:proposed', $disposal->getId()));
                }
            }
            $this->entityManager->flush();
        });

        return $disposal;
    }

    public function approve(DocumentDisposal $disposal, User $actor, ?string $comment): void
    {
        $this->assertAdministrator();
        $document = $disposal->getDocument();
        $now = new \DateTimeImmutable();
        $files = [];
        $this->entityManager->wrapInTransaction(function () use ($disposal, $document, $actor, $comment, $now, &$files): void {
            // Serialize with a legal hold or another decision on the same document.
            $this->entityManager->lock($document, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($document);
            $this->entityManager->refresh($disposal);
            $versions = $this->versionRepository->findForDocument($document);
            $attachments = $this->attachmentRepository->findBy(['document' => $document]);
            $manifest = [
                'registration_number' => $document->getRegistrationNumber(),
                'type' => $document->getType()->getCode(),
                'archived_at' => $document->getArchivedAt()?->format(\DateTimeInterface::ATOM),
                'retention_years' => $document->getType()->getRetentionYears(),
                'retain_until' => $disposal->getRetainUntil()->format('Y-m-d'),
                'versions' => array_map(static fn ($version): array => ['number' => $version->getNumber(), 'file' => $version->getOriginalName(), 'size_bytes' => $version->getSizeBytes(), 'sha256' => $version->getSha256()], $versions),
                'attachments' => array_map(static fn (DocumentAttachment $attachment): array => ['file' => $attachment->getOriginalName(), 'size_bytes' => $attachment->getSizeBytes(), 'sha256' => $attachment->getSha256()], $attachments),
            ];
            $disposal->approve($actor->getUserIdentifier(), $comment, $manifest, $now);
            $document->dispose($now);
            foreach ($versions as $version) {
                if ($version->getFileDisposedAt() === null) {
                    $files[] = $version->getStoredName();
                    $version->discardContent($now);
                }
            }
            foreach ($attachments as $attachment) {
                if ($attachment->getFileDisposedAt() === null) {
                    $files[] = $attachment->getStoredName();
                    $attachment->discardContent($now);
                }
            }
            foreach ($this->entityManager->getRepository(DocumentComment::class)->findBy(['document' => $document]) as $remark) {
                $this->entityManager->remove($remark);
            }
            $this->documents->record($document, $actor, 'disposed', ['disposal' => $disposal->getId(), 'proposed_by' => $disposal->getProposedBy(), 'versions' => count($versions), 'attachments' => count($attachments)]);
            $this->notifyProposer($disposal, sprintf('%s approved disposing of "%s".', $actor->getUserIdentifier(), $document->getTitle()));
            $this->entityManager->flush();
        });

        // Files go only after the tombstone is committed; a failure leaves an orphan file, never a lost record.
        foreach ($files as $storedName) {
            try {
                $this->storage->delete($storedName);
            } catch (\RuntimeException $exception) {
                $this->auditLogger->error('Disposed document file could not be removed from storage.', ['document_id' => $document->getId(), 'stored_name' => $storedName, 'error' => $exception->getMessage()]);
            }
        }
        $this->auditLogger->notice('Document disposed.', ['document_id' => $document->getId(), 'disposal_id' => $disposal->getId(), 'proposed_by' => $disposal->getProposedBy(), 'approved_by' => $actor->getUserIdentifier(), 'files' => count($files)]);
    }

    public function reject(DocumentDisposal $disposal, User $actor, ?string $comment): void
    {
        $this->assertAdministrator();
        $disposal->reject($actor->getUserIdentifier(), $comment, new \DateTimeImmutable());
        $this->documents->record($disposal->getDocument(), $actor, 'disposal_rejected', ['disposal' => $disposal->getId(), 'comment' => $disposal->getDecisionComment()]);
        $this->notifyProposer($disposal, sprintf('%s rejected disposing of "%s": %s', $actor->getUserIdentifier(), $disposal->getDocument()->getTitle(), (string) $disposal->getDecisionComment()));
        $this->entityManager->flush();
    }

    private function notifyProposer(DocumentDisposal $disposal, string $message): void
    {
        $proposer = $this->userRepository->findOneBy(['email' => $disposal->getProposedBy()]);
        if ($proposer instanceof User) {
            $this->notifier->disposal($proposer, $disposal->getDocument(), $message, sprintf('disposal:%d:decided', $disposal->getId()));
        }
    }

    private function assertAdministrator(): void
    {
        if (!$this->authorization->isGranted('ROLE_ADMIN')) {
            throw new DocumentAccessDenied('Only administrators can decide a disposal.');
        }
    }

    /** @return list<User> */
    private function administrators(): array
    {
        return array_values(array_filter($this->userRepository->findAll(), static fn (User $user): bool => in_array('ROLE_ADMIN', $user->getRoles(), true)));
    }
}
