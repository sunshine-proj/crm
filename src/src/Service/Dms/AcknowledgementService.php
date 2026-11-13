<?php

namespace App\Service\Dms;

use App\Entity\AcknowledgementRecipient;
use App\Entity\AcknowledgementRequest;
use App\Entity\Document;
use App\Entity\DocumentLink;
use App\Entity\User;
use App\Repository\AcknowledgementRecipientRepository;
use App\Repository\DocumentRepository;
use App\Repository\DocumentVersionRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Acknowledgement (familiarization) campaigns. Management (unit and section heads, within their own subtree) and
 * registry clerks (organization-wide) ask people to read a registered document's current version and confirm it.
 *
 * Roles on a request:
 * - controller: the sender and registry clerks; they add recipients, move the deadline, close and re-issue it;
 * - supervisor: a controller, or a head of the unit a recipient was recorded under; they remind and excuse;
 * - recipient: confirms personally. Deputies do not acknowledge on someone's behalf; absent people are excused.
 *
 * Exceptions: DocumentAccessDenied (403), NotFound (404), \InvalidArgumentException (400), \DomainException (409).
 */
final class AcknowledgementService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DocumentAccess $access,
        private readonly DocumentService $documents,
        private readonly AcknowledgementAudience $audience,
        private readonly DmsNotifier $notifier,
        private readonly AcknowledgementRecipientRepository $recipientRepository,
        private readonly DocumentVersionRepository $versionRepository,
        private readonly DocumentRepository $documentRepository,
    ) {
    }

    /** @return list<int>|null the unit ids the user may address; null means the whole organization, [] nothing */
    public function scopeOf(User $user): ?array
    {
        return $this->access->isRegistrar() ? null : $this->access->managedUnitIds($user);
    }

    /**
     * @param list<mixed> $targets
     */
    public function send(Document $document, User $actor, mixed $targets, ?string $message, ?\DateTimeImmutable $dueOn, bool $requireOpen, bool $addJoiners, ?AcknowledgementRequest $previous = null): AcknowledgementRequest
    {
        $this->access->assertRead($actor, $document);
        $scope = $this->scopeOf($actor);
        if ($scope === []) {
            throw new DocumentAccessDenied('Only unit heads and registry clerks can ask for acknowledgement.');
        }
        if (!in_array($document->getStatus(), Document::ACKNOWLEDGEABLE, true)) {
            throw new \DomainException('Only registered documents that are not archived or superseded can be sent for acknowledgement.');
        }
        $version = $this->versionRepository->findLatest($document) ?? throw new \DomainException('The document has no file to read.');
        $this->assertDueDate($dueOn);
        $chart = $this->access->chartToday();
        $normalized = $this->audience->normalize($targets, $scope, $chart);
        $people = $this->audience->resolve($normalized, $chart);
        unset($people[(int) $actor->getId()]);
        if ($people === []) {
            throw new \DomainException('Nobody else holds a post in the chosen units or positions.');
        }

        $request = new AcknowledgementRequest($document, $version, $actor, $normalized, $message, $dueOn, $requireOpen, $addJoiners, $previous);
        $this->entityManager->wrapInTransaction(function () use ($request, $people, $actor, $document, $version): void {
            $this->entityManager->persist($request);
            $recipients = $this->audience->enlist($request, $people, AcknowledgementRecipient::SENT, $actor->getUserIdentifier());
            $this->entityManager->flush();
            $this->audience->announce($request, $recipients);
            $this->documents->record($document, $actor, 'acknowledgement_requested', ['request' => $request->getId(), 'recipients' => count($recipients), 'version' => $version->getNumber()]);
            $this->entityManager->flush();
        });

        return $request;
    }

    /**
     * @param list<mixed> $targets
     * @return int people added
     */
    public function addTargets(AcknowledgementRequest $request, User $actor, mixed $targets): int
    {
        $this->assertControl($request, $actor);
        $request->assertOpen();
        $chart = $this->access->chartToday();
        $normalized = $this->audience->normalize($targets, $this->scopeOf($actor), $chart);
        $request->addTargets($normalized);
        $added = $this->audience->enlist($request, $this->audience->resolve($normalized, $chart), AcknowledgementRecipient::ADDED, $actor->getUserIdentifier());
        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // Someone added the same person at the same moment (another request or a joiner run).
            throw new \DomainException('The recipient list changed while you were adding people. Reload and try again.');
        }
        $this->audience->announce($request, $added);
        $this->documents->record($request->getDocument(), $actor, 'acknowledgement_recipients_added', ['request' => $request->getId(), 'count' => count($added)]);
        $this->entityManager->flush();

        return count($added);
    }

    /** Called when the user opens a version's file: the first opening of a requested version counts as viewed. */
    public function markViewed(Document $document, User $user, int $versionNumber): void
    {
        $changed = false;
        $now = new \DateTimeImmutable();
        foreach ($this->recipientRepository->findOpenRequestRowsFor($document, $user) as $recipient) {
            if ($recipient->getRequest()->getVersionNumber() === $versionNumber && $recipient->markViewed($now)) {
                $changed = true;
            }
        }
        if ($changed) {
            $this->entityManager->flush();
        }
    }

    public function acknowledge(AcknowledgementRecipient $recipient, User $actor): void
    {
        if ($recipient->getUser()->getId() !== $actor->getId()) {
            throw new DocumentAccessDenied('Only the person asked can confirm; acknowledgements are personal.');
        }
        $request = $recipient->getRequest();
        $this->entityManager->wrapInTransaction(function () use ($recipient, $request, $actor): void {
            // A double click must not confirm twice: re-read the row under a lock.
            $this->entityManager->lock($recipient, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($recipient);
            $recipient->acknowledge(new \DateTimeImmutable());
            $this->documents->record($request->getDocument(), $actor, 'acknowledged', ['request' => $request->getId(), 'version' => $request->getVersionNumber(), 'sha256' => $request->getVersionSha256()]);
            $this->entityManager->flush();
        });
        $this->announceCompletion($request);
    }

    public function excuse(AcknowledgementRecipient $recipient, User $actor, ?string $reason): void
    {
        $this->assertSupervise($recipient, $actor);
        $recipient->excuse((string) $reason, $actor->getUserIdentifier(), new \DateTimeImmutable());
        $this->documents->record($recipient->getRequest()->getDocument(), $actor, 'acknowledgement_excused', ['request' => $recipient->getRequest()->getId(), 'person' => $recipient->getUserEmail()]);
        $this->entityManager->flush();
        $this->announceCompletion($recipient->getRequest());
    }

    public function reinstate(AcknowledgementRecipient $recipient, User $actor): void
    {
        $this->assertSupervise($recipient, $actor);
        $recipient->reinstate();
        $this->documents->record($recipient->getRequest()->getDocument(), $actor, 'acknowledgement_reinstated', ['request' => $recipient->getRequest()->getId(), 'person' => $recipient->getUserEmail()]);
        $this->entityManager->flush();
    }

    /**
     * Reminds the chosen people (all who still owe a confirmation when $recipientIds is null) within the actor's
     * supervision. At most one manual reminder per person per day.
     *
     * @param list<int>|null $recipientIds
     * @return array{reminded: int, skipped: int}
     */
    public function remind(AcknowledgementRequest $request, User $actor, ?array $recipientIds): array
    {
        $this->access->assertRead($actor, $request->getDocument());
        $request->assertOpen();
        if (!$this->isController($request, $actor) && $this->access->managedUnitIds($actor) === []) {
            throw new DocumentAccessDenied('Only the sender, a registry clerk or the people\'s managers can send reminders.');
        }
        $chosen = [];
        $known = [];
        foreach ($this->recipientRepository->findForRequest($request) as $recipient) {
            $known[(int) $recipient->getId()] = true;
            if ($recipientIds !== null && !in_array($recipient->getId(), $recipientIds, true)) {
                continue;
            }
            if (!$this->canSupervise($recipient, $actor)) {
                if ($recipientIds !== null) {
                    throw new DocumentAccessDenied(sprintf('You do not supervise %s on this request.', $recipient->getUserEmail()));
                }
                continue;
            }
            $chosen[] = $recipient;
        }
        foreach ($recipientIds ?? [] as $id) {
            if (!isset($known[$id])) {
                throw new NotFound('Recipient not found on this request.');
            }
        }

        $now = new \DateTimeImmutable();
        $document = $request->getDocument();
        $due = $request->getDueOn() !== null ? sprintf(' (due %s)', $request->getDueOn()->format('Y-m-d')) : '';
        $result = ['reminded' => 0, 'skipped' => 0];
        foreach ($chosen as $recipient) {
            if (!$recipient->isOpen()) {
                ++$result['skipped'];
                continue;
            }
            $sent = $this->notifier->acknowledgement($recipient->getUser(), $document, sprintf('Reminder from %s: please read and acknowledge "%s"%s.', $actor->getUserIdentifier(), $document->getTitle(), $due), sprintf('ack:%d:remind:%s', $recipient->getId(), $now->format('Y-m-d')));
            if ($sent) {
                $recipient->reminded($now);
                ++$result['reminded'];
            } else {
                ++$result['skipped'];
            }
        }
        if ($result['reminded'] > 0) {
            $this->documents->record($document, $actor, 'acknowledgement_reminded', ['request' => $request->getId(), 'count' => $result['reminded']]);
        }
        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // Another reminder for the same people was sent at the same moment; each person gets one per day.
            throw new \DomainException('Reminders were just sent by someone else.');
        }

        return $result;
    }

    public function extend(AcknowledgementRequest $request, User $actor, ?\DateTimeImmutable $dueOn): void
    {
        $this->assertControl($request, $actor);
        if ($dueOn === null) {
            throw new \InvalidArgumentException('Give the new deadline.');
        }
        $request->extendTo($dueOn, new \DateTimeImmutable('today'));
        $this->documents->record($request->getDocument(), $actor, 'acknowledgement_deadline_moved', ['request' => $request->getId(), 'due_on' => $dueOn->format('Y-m-d')]);
        $this->entityManager->flush();
    }

    public function close(AcknowledgementRequest $request, User $actor): void
    {
        $this->assertControl($request, $actor);
        $request->close($actor->getUserIdentifier(), new \DateTimeImmutable());
        $this->documents->record($request->getDocument(), $actor, 'acknowledgement_closed', ['request' => $request->getId()]);
        $this->entityManager->flush();
    }

    /**
     * Asks the same audience to acknowledge the new edition: the same document when it has a newer version, or a
     * document that replaces or amends it. The old request is closed.
     */
    public function reissue(AcknowledgementRequest $request, User $actor, ?int $documentId, ?\DateTimeImmutable $dueOn, ?string $message, ?bool $requireOpen): AcknowledgementRequest
    {
        $this->assertControl($request, $actor);
        $old = $request->getDocument();
        if ($documentId === null || $documentId === $old->getId()) {
            $target = $old;
            if (($this->versionRepository->findLatest($old)?->getNumber() ?? 0) <= $request->getVersionNumber()) {
                throw new \DomainException('The document has no newer version. Choose the document that replaces or amends it.');
            }
        } else {
            $target = $this->documentRepository->find($documentId);
            if (!$target instanceof Document || !$this->access->canRead($actor, $target)) {
                throw new NotFound('Document not found.');
            }
            $linked = $this->entityManager->getRepository(DocumentLink::class)->findBy(['source' => $target, 'target' => $old]);
            if (array_filter($linked, static fn (DocumentLink $link): bool => in_array($link->getKind(), ['replaces', 'amends'], true)) === []) {
                throw new \DomainException('Only a document linked as replacing or amending this one can take over its acknowledgement.');
            }
        }
        $targets = array_map(static fn (array $target): array => ['kind' => $target['kind'], 'id' => $target['id'], 'include_subunits' => $target['include_subunits']], $request->getTargets());
        $new = $this->send($target, $actor, $targets, $message ?? $request->getMessage(), $dueOn, $requireOpen ?? $request->requiresOpening(), $request->addsJoiners(), $request);
        if ($request->isOpen()) {
            $request->close($actor->getUserIdentifier(), new \DateTimeImmutable());
            $this->documents->record($old, $actor, 'acknowledgement_closed', ['request' => $request->getId(), 'reissued_as' => $new->getId()]);
            $this->entityManager->flush();
        }

        return $new;
    }

    public function isController(AcknowledgementRequest $request, User $user): bool
    {
        return $request->getSender()->getId() === $user->getId() || $this->access->isRegistrar();
    }

    /** Controllers supervise everyone; unit heads supervise the people recorded under the units they manage. */
    public function canSupervise(AcknowledgementRecipient $recipient, User $user): bool
    {
        if ($this->isController($recipient->getRequest(), $user)) {
            return true;
        }
        $unitId = $recipient->getUnit()?->getId();

        return $unitId !== null && $recipient->getUser()->getId() !== $user->getId() && in_array($unitId, $this->access->managedUnitIds($user), true);
    }

    /**
     * Whether the user may see the request's status board, and which units of it: null for all of it, a list of
     * unit ids for a manager who sees only their part, false when they may not see it at all.
     *
     * @return list<int>|null|false
     */
    public function monitorScope(AcknowledgementRequest $request, User $user, array $recipients): array|null|false
    {
        if (!$this->access->canRead($user, $request->getDocument())) {
            return false;
        }
        if ($this->isController($request, $user)) {
            return null;
        }
        $managed = $this->access->managedUnitIds($user);
        foreach ($recipients as $recipient) {
            if (in_array($recipient->getUnit()?->getId(), $managed, true)) {
                return $managed;
            }
        }

        return false;
    }

    private function assertControl(AcknowledgementRequest $request, User $actor): void
    {
        $this->access->assertRead($actor, $request->getDocument());
        if (!$this->isController($request, $actor)) {
            throw new DocumentAccessDenied('Only the sender or a registry clerk can change this request.');
        }
    }

    private function assertSupervise(AcknowledgementRecipient $recipient, User $actor): void
    {
        $this->access->assertRead($actor, $recipient->getRequest()->getDocument());
        if ($recipient->getUser()->getId() === $actor->getId() && !$this->access->isRegistrar()) {
            throw new DocumentAccessDenied('You cannot excuse yourself.');
        }
        if (!$this->canSupervise($recipient, $actor)) {
            throw new DocumentAccessDenied('Only the sender, a registry clerk or the person\'s manager can do this.');
        }
    }

    private function assertDueDate(?\DateTimeImmutable $dueOn): void
    {
        if ($dueOn !== null && $dueOn->format('Y-m-d') < (new \DateTimeImmutable('today'))->format('Y-m-d')) {
            throw new \InvalidArgumentException('The deadline cannot be in the past.');
        }
    }

    /** Tells the sender once when nobody owes a confirmation any more. */
    private function announceCompletion(AcknowledgementRequest $request): void
    {
        if ($request->isOpen() && $this->recipientRepository->countOpen($request) === 0) {
            $document = $request->getDocument();
            if ($this->notifier->acknowledgement($request->getSender(), $document, sprintf('Everyone asked has acknowledged "%s" or is excused.', $document->getTitle()), sprintf('ack:%d:complete', $request->getId()))) {
                $this->entityManager->flush();
            }
        }
    }
}
