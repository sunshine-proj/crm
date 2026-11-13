<?php

namespace App\Service\Dms;

use App\Entity\Document;
use App\Entity\DocumentAttachment;
use App\Entity\DocumentEvent;
use App\Entity\DocumentLink;
use App\Entity\DocumentType;
use App\Entity\DocumentVersion;
use App\Entity\User;

/** JSON response shapes for the document registry (snake_case keys, ISO 8601 timestamps, YYYY-MM-DD dates). */
final class DocumentViews
{
    public function __construct(private readonly DocumentAccess $access)
    {
    }

    /** @return array<string, mixed> */
    public function type(DocumentType $type): array
    {
        return [
            'id' => $type->getId(),
            'code' => $type->getCode(),
            'name' => $type->getName(),
            'description' => $type->getDescription(),
            'number_pattern' => $type->getNumberPattern(),
            'number_example' => $type->formatNumber(1, new \DateTimeImmutable(), null),
            'requires_approval' => $type->requiresApproval(),
            'default_confidentiality' => $type->getDefaultConfidentiality(),
            'retention_years' => $type->getRetentionYears(),
            'required_fields' => $type->getRequiredFields(),
            'active' => $type->isActive(),
            'route_template' => $type->getRouteTemplate(),
            'auto_register' => $type->autoRegisters(),
            'resubmit_mode' => $type->getResubmitMode(),
        ];
    }

    /** @return array<string, mixed> list row */
    public function summary(Document $document, ?DocumentVersion $current, User $viewer): array
    {
        return [
            'id' => $document->getId(),
            'type' => ['id' => $document->getType()->getId(), 'code' => $document->getType()->getCode(), 'name' => $document->getType()->getName()],
            'title' => $document->getTitle(),
            'status' => $document->getStatus(),
            'confidentiality' => $document->getConfidentiality(),
            'registration_number' => $document->getRegistrationNumber(),
            'registered_at' => $document->getRegisteredAt()?->format(\DateTimeInterface::ATOM),
            'author' => ['id' => $document->getAuthor()->getId(), 'email' => $document->getAuthor()->getUserIdentifier()],
            'owner_unit' => $document->getOwnerUnit() !== null ? ['id' => $document->getOwnerUnit()->getId(), 'name' => $document->getOwnerUnit()->getName()] : null,
            'tags' => $document->getTags(),
            'effective_on' => $document->getEffectiveOn()?->format('Y-m-d'),
            'expires_on' => $document->getExpiresOn()?->format('Y-m-d'),
            'version_count' => $document->getVersionCount(),
            'current_version' => $current !== null ? $this->version($current) : null,
            'checked_out_by' => $document->getCheckedOutBy() !== null ? ['id' => $document->getCheckedOutBy()->getId(), 'email' => $document->getCheckedOutBy()->getUserIdentifier()] : null,
            'created_at' => $document->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $document->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            'permissions' => $this->access->permissions($viewer, $document),
        ];
    }

    /**
     * @param list<DocumentVersion> $versions newest first
     * @param list<DocumentAttachment> $attachments
     * @param list<DocumentLink> $links
     * @param list<DocumentEvent> $events
     * @return array<string, mixed>
     */
    public function detail(Document $document, array $versions, array $attachments, array $links, array $events, User $viewer): array
    {
        $id = $document->getId();

        return $this->summary($document, $versions[0] ?? null, $viewer) + [
            'summary' => $document->getSummary(),
            'review_on' => $document->getReviewOn()?->format('Y-m-d'),
            'registered_by' => $document->getRegisteredBy(),
            'archived_at' => $document->getArchivedAt()?->format(\DateTimeInterface::ATOM),
            'checked_out_at' => $document->getCheckedOutAt()?->format(\DateTimeInterface::ATOM),
            'client' => $document->getClient() !== null ? ['id' => $document->getClient()->getId(), 'title' => $document->getClient()->getTitle()] : null,
            'project' => $document->getProject() !== null ? ['id' => $document->getProject()->getId(), 'title' => $document->getProject()->getTitle()] : null,
            'deal' => $document->getDeal() !== null ? ['id' => $document->getDeal()->getId(), 'title' => $document->getDeal()->getTitle()] : null,
            'readers' => array_map(static fn (User $user): array => ['id' => $user->getId(), 'email' => $user->getUserIdentifier()], $document->getReaders()),
            'missing_required_fields' => $document->missingRequiredFields(),
            'versions' => array_map($this->version(...), $versions),
            'attachments' => array_map(static fn (DocumentAttachment $attachment): array => [
                'id' => $attachment->getId(), 'original_name' => $attachment->getOriginalName(), 'mime_type' => $attachment->getMimeType(),
                'size_bytes' => $attachment->getSizeBytes(), 'sha256' => $attachment->getSha256(), 'uploaded_by' => $attachment->getUploadedByEmail(),
                'created_at' => $attachment->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ], $attachments),
            'links' => array_values(array_filter(array_map(function (DocumentLink $link) use ($id, $viewer): ?array {
                $outgoing = $link->getSource()->getId() === $id;
                $other = $outgoing ? $link->getTarget() : $link->getSource();
                if (!$this->access->canRead($viewer, $other)) {
                    return null;
                }

                return [
                    'id' => $link->getId(), 'kind' => $link->getKind(), 'direction' => $outgoing ? 'outgoing' : 'incoming',
                    'document' => ['id' => $other->getId(), 'title' => $other->getTitle(), 'registration_number' => $other->getRegistrationNumber(), 'status' => $other->getStatus()],
                    'created_by' => $link->getCreatedBy(), 'created_at' => $link->getCreatedAt()->format(\DateTimeInterface::ATOM),
                ];
            }, $links))),
            'events' => array_map(static fn (DocumentEvent $event): array => [
                'id' => $event->getId(), 'action' => $event->getAction(), 'actor' => $event->getActor(), 'details' => $event->getDetails(),
                'created_at' => $event->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ], $events),
        ];
    }

    /** @return array<string, mixed> */
    public function version(DocumentVersion $version): array
    {
        return [
            'number' => $version->getNumber(),
            'original_name' => $version->getOriginalName(),
            'mime_type' => $version->getMimeType(),
            'size_bytes' => $version->getSizeBytes(),
            'sha256' => $version->getSha256(),
            'uploaded_by' => $version->getUploadedByEmail(),
            'comment' => $version->getComment(),
            'previewable' => DmsFileStorage::isPreviewable($version->getMimeType()),
            'has_text' => $version->getExtractedText() !== null,
            'created_at' => $version->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
