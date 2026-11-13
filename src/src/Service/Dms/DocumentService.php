<?php

namespace App\Service\Dms;

use App\Dto\DocumentInput;
use App\Entity\Client;
use App\Entity\Deal;
use App\Entity\Document;
use App\Entity\DocumentAttachment;
use App\Entity\DocumentEvent;
use App\Entity\DocumentLink;
use App\Entity\DocumentType;
use App\Entity\DocumentVersion;
use App\Entity\OrgUnit;
use App\Entity\Project;
use App\Entity\StoredFile;
use App\Entity\User;
use App\Repository\DocumentLinkRepository;
use App\Repository\DocumentRepository;
use App\Repository\DocumentTypeRepository;
use App\Repository\DocumentVersionRepository;
use App\Repository\UserRepository;
use App\Service\Org\SubordinationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Document registry use cases. Every method checks access through DocumentAccess, applies the change through the
 * entities, records a DocumentEvent and flushes. Exceptions: DocumentAccessDenied (403), \DomainException for state
 * conflicts (409), \InvalidArgumentException for bad input (400), NotFound (404) for missing related records.
 */
final class DocumentService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DocumentAccess $access,
        private readonly DmsFileStorage $storage,
        private readonly DocumentTextExtractor $extractor,
        private readonly RegistryNumbering $numbering,
        private readonly DocumentTypeRepository $typeRepository,
        private readonly DocumentRepository $documentRepository,
        private readonly DocumentVersionRepository $versionRepository,
        private readonly DocumentLinkRepository $linkRepository,
        private readonly UserRepository $userRepository,
        private readonly SubordinationService $subordination,
    ) {
    }

    public function create(DocumentInput $input, User $actor): Document
    {
        if ($input->type_id === null || $input->title === null) {
            throw new \InvalidArgumentException('Document type and title are required.');
        }
        $type = $this->typeRepository->find($input->type_id) ?? throw new NotFound('Document type not found.');
        $document = new Document($type, $actor, $input->title);
        $this->applyDetails($document, $input);
        if ($input->owner_unit_id === null && !$input->clear_owner_unit) {
            // By default a document belongs to its author's own unit, which lets that unit's heads see the draft.
            $home = $this->subordination->chartOn()->primaryEmployment($actor)?->getPosition()->getUnit();
            $document->describe($document->getSummary(), $home, $document->getConfidentiality(), $document->getTags());
        }
        $document->refreshSearchText(null);
        $this->entityManager->persist($document);
        $this->record($document, $actor, 'created', ['type' => $type->getCode()]);
        $this->entityManager->flush();

        return $document;
    }

    public function update(Document $document, DocumentInput $input, User $actor): void
    {
        $this->access->assertEditContent($actor, $document);
        if ($input->type_id !== null && $input->type_id !== $document->getType()->getId()) {
            throw new \InvalidArgumentException('The document type cannot be changed; create a new document instead.');
        }
        if ($input->title !== null) {
            $document->retitle($input->title);
        }
        $this->applyDetails($document, $input);
        $document->refreshSearchText($this->versionRepository->findLatest($document)?->getExtractedText());
        $this->record($document, $actor, 'updated');
        $this->entityManager->flush();
    }

    public function uploadVersion(Document $document, UploadedFile $file, ?string $comment, bool $checkIn, User $actor): DocumentVersion
    {
        $this->access->assertEditContent($actor, $document);
        $previousStatus = $document->getStatus();
        $stored = $this->storage->store($file);
        try {
            $text = $this->extractor->extract($this->storage->path($stored->storedName), $stored->mimeType);
            $version = new DocumentVersion($document, $document->addVersion(), $stored, $actor, $comment, $text);
            $document->refreshSearchText($text);
            if ($checkIn && $document->getCheckedOutBy()?->getId() === $actor->getId()) {
                $document->checkIn();
            }
            $this->entityManager->persist($version);
            $this->record($document, $actor, 'version_added', ['version' => $version->getNumber(), 'file' => $stored->originalName, 'sha256' => $stored->sha256]);
            if ($previousStatus !== $document->getStatus()) {
                $this->record($document, $actor, 'returned_to_draft', ['reason' => 'New version after approval']);
            }
            $this->entityManager->flush();
        } catch (\Throwable $exception) {
            $this->storage->delete($stored->storedName);
            throw $exception;
        }

        return $version;
    }

    /** Takes the edit lock. A clerk may break someone else's lock with $force; that is recorded separately. */
    public function checkOut(Document $document, User $actor, bool $force): void
    {
        $this->access->assertManage($actor, $document);
        $holder = $document->getCheckedOutBy();
        if ($holder?->getId() === $actor->getId()) {
            throw new \DomainException('You already have this document checked out.');
        }
        if ($holder !== null) {
            if (!$force || !$this->access->isRegistrar()) {
                throw new \DomainException(sprintf('The document is checked out by %s.', $holder->getUserIdentifier()));
            }
            $this->record($document, $actor, 'lock_overridden', ['previous_holder' => $holder->getUserIdentifier()]);
        }
        $document->checkOut($actor);
        $this->record($document, $actor, 'checked_out');
        $this->entityManager->flush();
    }

    public function checkIn(Document $document, User $actor): void
    {
        $this->access->assertRead($actor, $document);
        $holder = $document->getCheckedOutBy();
        if ($holder === null) {
            throw new \DomainException('The document is not checked out.');
        }
        if ($holder->getId() !== $actor->getId()) {
            if (!$this->access->isRegistrar()) {
                throw new DocumentAccessDenied('Only the person who checked the document out, or a registry clerk, can check it in.');
            }
            $this->record($document, $actor, 'lock_overridden', ['previous_holder' => $holder->getUserIdentifier()]);
        }
        $document->checkIn();
        $this->record($document, $actor, 'checked_in');
        $this->entityManager->flush();
    }

    public function register(Document $document, User $actor): void
    {
        $this->access->assertRegistrar();
        $this->registerAs($document, $actor->getUserIdentifier());
    }

    /** Registration triggered by the final approval of a type that registers automatically. */
    public function registerAutomatically(Document $document): void
    {
        $this->registerAs($document, 'automatic registration');
    }

    private function registerAs(Document $document, string $registrar): void
    {
        $now = new \DateTimeImmutable();
        // Validate before any number is taken; the entity re-checks inside the transaction.
        $document->assertRegistrable();
        $this->numbering->prepare($document->getType(), $now);
        $this->entityManager->wrapInTransaction(function () use ($document, $registrar, $now): void {
            $number = $this->numbering->allocate($document->getType(), $now, $document->getOwnerUnit());
            $document->register($number, $registrar, $now);
            $document->refreshSearchText($this->versionRepository->findLatest($document)?->getExtractedText());
            $this->record($document, $registrar, 'registered', ['number' => $number, 'status' => $document->getStatus()]);
            $this->entityManager->flush();
        });
    }

    public function archive(Document $document, User $actor): void
    {
        $this->access->assertRegistrar();
        $document->archive();
        $this->record($document, $actor, 'archived');
        $this->entityManager->flush();
    }

    /** Deletes an unregistered document with its versions, attachments and files. */
    public function delete(Document $document, User $actor): void
    {
        $this->access->assertManage($actor, $document);
        if (!$document->isDeletable()) {
            throw new \DomainException('Only unregistered drafts can be deleted; registered documents are archived.');
        }
        if ($document->isLockedFor($actor)) {
            throw new \DomainException(sprintf('The document is checked out by %s.', $document->getCheckedOutBy()?->getUserIdentifier()));
        }
        $files = array_map(static fn (DocumentVersion $version): string => $version->getStoredName(), $this->versionRepository->findForDocument($document));
        foreach ($this->entityManager->getRepository(DocumentAttachment::class)->findBy(['document' => $document]) as $attachment) {
            $files[] = $attachment->getStoredName();
        }
        $this->entityManager->remove($document);
        $this->entityManager->flush();
        foreach ($files as $storedName) {
            $this->storage->delete($storedName);
        }
    }

    /** @param list<int> $userIds */
    public function replaceReaders(Document $document, array $userIds, User $actor): void
    {
        $this->access->assertManage($actor, $document);
        $users = [];
        foreach (array_unique($userIds) as $id) {
            $users[] = $this->userRepository->find($id) ?? throw new NotFound(sprintf('User %d not found.', $id));
        }
        $document->replaceReaders($users);
        $this->record($document, $actor, 'readers_changed', ['count' => count($document->getReaders())]);
        $this->entityManager->flush();
    }

    public function addAttachment(Document $document, UploadedFile $file, User $actor): DocumentAttachment
    {
        $this->access->assertEditContent($actor, $document);
        $stored = $this->storage->store($file);
        try {
            $attachment = new DocumentAttachment($document, $stored, $actor);
            $this->entityManager->persist($attachment);
            $this->record($document, $actor, 'attachment_added', ['file' => $stored->originalName, 'sha256' => $stored->sha256]);
            $this->entityManager->flush();
        } catch (\Throwable $exception) {
            $this->storage->delete($stored->storedName);
            throw $exception;
        }

        return $attachment;
    }

    public function removeAttachment(DocumentAttachment $attachment, User $actor): void
    {
        $document = $attachment->getDocument();
        $this->access->assertEditContent($actor, $document);
        $storedName = $attachment->getStoredName();
        $this->entityManager->remove($attachment);
        $this->record($document, $actor, 'attachment_removed', ['file' => $attachment->getOriginalName()]);
        $this->entityManager->flush();
        $this->storage->delete($storedName);
    }

    public function addLink(Document $source, int $targetId, string $kind, User $actor): DocumentLink
    {
        $this->access->assertManage($actor, $source);
        if ($source->getStatus() === Document::ARCHIVED) {
            throw new \DomainException('An archived document cannot get new links.');
        }
        $target = $this->documentRepository->find($targetId);
        if (!$target instanceof Document || !$this->access->canRead($actor, $target)) {
            throw new NotFound('Linked document not found.');
        }
        if ($this->linkRepository->exists($source, $target, $kind)) {
            throw new \DomainException('This link already exists.');
        }
        $link = new DocumentLink($source, $target, $kind, $actor->getUserIdentifier());
        $this->entityManager->persist($link);
        $this->record($source, $actor, 'link_added', ['kind' => $kind, 'target_id' => $target->getId()]);
        $this->entityManager->flush();

        return $link;
    }

    public function removeLink(DocumentLink $link, User $actor): void
    {
        $this->access->assertManage($actor, $link->getSource());
        $this->entityManager->remove($link);
        $this->record($link->getSource(), $actor, 'link_removed', ['kind' => $link->getKind(), 'target_id' => $link->getTarget()->getId()]);
        $this->entityManager->flush();
    }

    /** Downloads are part of the document's trail (who obtained which exact file). */
    public function recordDownload(Document $document, User $actor, string $what, string $sha256): void
    {
        $this->record($document, $actor, 'downloaded', ['file' => $what, 'sha256' => $sha256]);
        $this->entityManager->flush();
    }

    private function applyDetails(Document $document, DocumentInput $input): void
    {
        $ownerUnit = $document->getOwnerUnit();
        if ($input->clear_owner_unit) {
            $ownerUnit = null;
        } elseif ($input->owner_unit_id !== null) {
            $ownerUnit = $this->entityManager->find(OrgUnit::class, $input->owner_unit_id) ?? throw new NotFound('Owner unit not found.');
        }
        $document->describe(
            $input->summary ?? $document->getSummary(),
            $ownerUnit,
            $input->confidentiality ?? $document->getConfidentiality(),
            $input->tags ?? $document->getTags(),
        );
        $document->schedule(
            $this->date($input->effective_on, $input->clear_effective_on, $document->getEffectiveOn()),
            $this->date($input->expires_on, $input->clear_expires_on, $document->getExpiresOn()),
            $this->date($input->review_on, $input->clear_review_on, $document->getReviewOn()),
        );
        $document->relateTo(
            $this->related(Client::class, $input->client_id, $input->clear_client, $document->getClient(), 'Client'),
            $this->related(Project::class, $input->project_id, $input->clear_project, $document->getProject(), 'Project'),
            $this->related(Deal::class, $input->deal_id, $input->clear_deal, $document->getDeal(), 'Deal'),
        );
    }

    private function date(?string $value, bool $clear, ?\DateTimeImmutable $current): ?\DateTimeImmutable
    {
        if ($clear) {
            return null;
        }
        if ($value === null) {
            return $current;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('Dates must be valid calendar dates in YYYY-MM-DD form.');
        }

        return $date;
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @param T|null $current
     * @return T|null
     */
    private function related(string $class, ?int $id, bool $clear, ?object $current, string $label): ?object
    {
        if ($clear) {
            return null;
        }

        return $id === null ? $current : ($this->entityManager->find($class, $id) ?? throw new NotFound($label.' not found.'));
    }

    /** @param array<string, scalar|null> $details */
    public function record(Document $document, User|string $actor, string $action, array $details = []): void
    {
        $this->entityManager->persist(new DocumentEvent($document, $actor instanceof User ? $actor->getUserIdentifier() : $actor, $action, $details));
    }
}
