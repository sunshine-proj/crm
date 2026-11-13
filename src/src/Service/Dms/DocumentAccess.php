<?php

namespace App\Service\Dms;

use App\Entity\Document;
use App\Entity\User;
use App\Repository\AcknowledgementRecipientRepository;
use App\Repository\ApprovalStepRepository;
use App\Service\Org\OrgChart;
use App\Service\Org\SubordinationService;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Who may do what with a document, for the signed-in user.
 *
 * Read: registry clerks (ROLE_DMS_ADMIN); the author; named readers; approval participants (and their deputies while
 * they act for them); people asked to acknowledge it; for general (non-confidential) documents also
 * heads of the owner unit or a unit above it, and everyone once the document is registered. Confidential documents
 * are never visible beyond the author, named readers and clerks.
 * Manage (metadata, files, readers, links, delete drafts): the author and clerks. Content changes also need an
 * editable status and no check-out by somebody else. Registration and archiving: clerks only. Asking for
 * acknowledgement: unit heads (for their own subtree) and clerks, once the document is registered and in force.
 * DocumentRepository::search() applies the same read rule in the query.
 */
final class DocumentAccess
{
    private ?OrgChart $chart = null;

    public function __construct(
        private readonly AuthorizationCheckerInterface $authorization,
        private readonly SubordinationService $subordination,
        private readonly ApprovalStepRepository $stepRepository,
        private readonly AcknowledgementRecipientRepository $recipientRepository,
    ) {
    }

    public function isRegistrar(): bool
    {
        return $this->authorization->isGranted('ROLE_DMS_ADMIN');
    }

    /** @return list<int> */
    public function managedUnitIds(User $user): array
    {
        return $this->chart()->managedUnitIds($user);
    }

    /** @return list<int> the user and everyone they currently deputize for */
    public function actingForIds(User $user): array
    {
        return [(int) $user->getId(), ...array_map(static fn (User $principal): int => (int) $principal->getId(), $this->chart()->principalsOf($user))];
    }

    public function chartToday(): OrgChart
    {
        return $this->chart();
    }

    public function canRead(User $user, Document $document): bool
    {
        if ($this->isRegistrar() || $this->canManage($user, $document) || $document->hasReader($user)) {
            return true;
        }
        if ($this->stepRepository->isParticipant($document, $this->actingForIds($user)) || $this->recipientRepository->isRecipient($document, $user)) {
            return true;
        }
        if ($document->isConfidential()) {
            return false;
        }
        if ($document->isRegistered()) {
            return true;
        }
        $owner = $document->getOwnerUnit()?->getId();

        return $owner !== null && in_array($owner, $this->managedUnitIds($user), true);
    }

    public function canManage(User $user, Document $document): bool
    {
        return $document->getAuthor()->getId() === $user->getId() || $this->isRegistrar();
    }

    public function canEditContent(User $user, Document $document): bool
    {
        return $this->canManage($user, $document) && $document->isEditable() && !$document->isLockedFor($user);
    }

    public function assertRead(User $user, Document $document): void
    {
        if (!$this->canRead($user, $document)) {
            throw new DocumentAccessDenied('You do not have access to this document.');
        }
    }

    public function assertManage(User $user, Document $document): void
    {
        $this->assertRead($user, $document);
        if (!$this->canManage($user, $document)) {
            throw new DocumentAccessDenied('Only the author or a registry clerk can change this document.');
        }
    }

    /** Throws 403 for people who may not manage it, 409 when its state or a check-out by someone else blocks the change. */
    public function assertEditContent(User $user, Document $document): void
    {
        $this->assertManage($user, $document);
        if (!$document->isEditable()) {
            throw new \DomainException('The document can no longer be edited in its current state.');
        }
        if ($document->isLockedFor($user)) {
            throw new \DomainException(sprintf('The document is checked out by %s.', $document->getCheckedOutBy()?->getUserIdentifier()));
        }
    }

    public function assertRegistrar(): void
    {
        if (!$this->isRegistrar()) {
            throw new DocumentAccessDenied('Only registry clerks can do this.');
        }
    }

    /** @return array<string, bool> what the UI may offer; the API re-checks each action */
    public function permissions(User $user, Document $document): array
    {
        $manage = $this->canManage($user, $document);
        $clerk = $this->isRegistrar();

        return [
            'can_manage' => $manage,
            'can_edit' => $this->canEditContent($user, $document),
            'can_check_out' => $manage && $document->isEditable() && $document->getCheckedOutBy() === null,
            'can_check_in' => $document->getCheckedOutBy() !== null && ($document->getCheckedOutBy()->getId() === $user->getId() || $clerk),
            'can_register' => $clerk && $document->canBeRegistered(),
            'can_archive' => $clerk && in_array($document->getStatus(), [Document::REGISTERED, Document::IN_FORCE, Document::SUPERSEDED], true),
            'can_delete' => $manage && $document->isDeletable(),
            'can_submit' => $manage && $document->canBeSubmitted() && $document->getVersionCount() > 0 && $document->getCheckedOutBy() === null,
            'can_withdraw' => $manage && $document->getStatus() === Document::IN_APPROVAL,
            'can_place_legal_hold' => $clerk && !$document->isOnLegalHold() && $document->getStatus() !== Document::DISPOSED,
            'can_release_legal_hold' => $clerk && $document->isOnLegalHold(),
            'can_record_review' => $manage && in_array($document->getStatus(), [Document::REGISTERED, Document::IN_FORCE], true),
            'can_view_audit' => $manage,
            'can_propose_disposal' => $clerk && $document->isDueForDisposal(new \DateTimeImmutable('today')),
            'can_request_acknowledgement' => in_array($document->getStatus(), Document::ACKNOWLEDGEABLE, true) && ($clerk || $this->managedUnitIds($user) !== []),
        ];
    }

    private function chart(): OrgChart
    {
        return $this->chart ??= $this->subordination->chartOn();
    }
}
