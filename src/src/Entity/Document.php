<?php

namespace App\Entity;

use App\Repository\DocumentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A managed document: metadata, an ordered series of immutable file versions (latest = current), a lifecycle status
 * and, once registered, a gapless registration number. Content and metadata change only while the document is in an
 * editable state; a registered document is superseded by a new one rather than edited.
 */
#[ORM\Entity(repositoryClass: DocumentRepository::class)]
#[ORM\Table(name: 'document')]
#[ORM\UniqueConstraint(name: 'uniq_document_registration_number', columns: ['registration_number'])]
#[ORM\Index(columns: ['status', 'type_id'], name: 'idx_document_status_type')]
#[ORM\Index(columns: ['author_id'], name: 'idx_document_author')]
#[ORM\Index(columns: ['owner_unit_id'], name: 'idx_document_owner_unit')]
class Document
{
    public const DRAFT = 'draft';
    public const IN_APPROVAL = 'in_approval';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    public const WITHDRAWN = 'withdrawn';
    public const REGISTERED = 'registered';
    public const IN_FORCE = 'in_force';
    public const SUPERSEDED = 'superseded';
    public const ARCHIVED = 'archived';
    public const STATUSES = [self::DRAFT, self::IN_APPROVAL, self::APPROVED, self::REJECTED, self::WITHDRAWN, self::REGISTERED, self::IN_FORCE, self::SUPERSEDED, self::ARCHIVED];
    /** States in which content and metadata may change. */
    public const EDITABLE = [self::DRAFT, self::REJECTED, self::WITHDRAWN, self::APPROVED];
    /** States after registration; such documents are visible organization-wide unless confidential. */
    public const REGISTERED_STATES = [self::REGISTERED, self::IN_FORCE, self::SUPERSEDED, self::ARCHIVED];

    public const GENERAL = 'general';
    public const CONFIDENTIAL = 'confidential';
    public const CONFIDENTIALITY_LEVELS = [self::GENERAL, self::CONFIDENTIAL];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: DocumentType::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private DocumentType $type;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $author;

    #[ORM\ManyToOne(targetEntity: OrgUnit::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?OrgUnit $ownerUnit = null;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $summary = null;

    #[ORM\Column(length: 16, options: ['default' => 'draft'])]
    private string $status = self::DRAFT;

    #[ORM\Column(length: 16, options: ['default' => 'general'])]
    private string $confidentiality = self::GENERAL;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $registrationNumber = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $registeredAt = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $registeredBy = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $effectiveOn = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $expiresOn = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $reviewOn = null;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $tags = [];

    #[ORM\ManyToOne(targetEntity: Client::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Client $client = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Project $project = null;

    #[ORM\ManyToOne(targetEntity: Deal::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Deal $deal = null;

    /** People allowed to read the document in addition to the author and registry clerks (required for confidential ones). */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'document_reader')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(onDelete: 'CASCADE')]
    private Collection $readers;

    #[ORM\Column(options: ['default' => 0])]
    private int $versionCount = 0;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $checkedOutBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $checkedOutAt = null;

    /** Lower-cased number, title, summary, `[tag:<tag>]` markers, type name and current-version text, searched with LIKE. */
    #[ORM\Column(type: Types::TEXT, options: ['default' => ''])]
    private string $searchText = '';

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $archivedAt = null;

    public function __construct(DocumentType $type, User $author, string $title)
    {
        if (!$type->isActive()) {
            throw new \DomainException('This document type is no longer in use.');
        }
        $this->type = $type;
        $this->author = $author;
        $this->confidentiality = $type->getDefaultConfidentiality();
        $this->readers = new ArrayCollection();
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
        $this->retitle($title);
    }

    public function getId(): ?int { return $this->id; }
    public function getType(): DocumentType { return $this->type; }
    public function getAuthor(): User { return $this->author; }
    public function getOwnerUnit(): ?OrgUnit { return $this->ownerUnit; }
    public function getTitle(): string { return $this->title; }
    public function getSummary(): ?string { return $this->summary; }
    public function getStatus(): string { return $this->status; }
    public function getConfidentiality(): string { return $this->confidentiality; }
    public function isConfidential(): bool { return $this->confidentiality === self::CONFIDENTIAL; }
    public function getRegistrationNumber(): ?string { return $this->registrationNumber; }
    public function getRegisteredAt(): ?\DateTimeImmutable { return $this->registeredAt; }
    public function getRegisteredBy(): ?string { return $this->registeredBy; }
    public function getEffectiveOn(): ?\DateTimeImmutable { return $this->effectiveOn; }
    public function getExpiresOn(): ?\DateTimeImmutable { return $this->expiresOn; }
    public function getReviewOn(): ?\DateTimeImmutable { return $this->reviewOn; }
    /** @return list<string> */
    public function getTags(): array { return $this->tags; }
    public function getClient(): ?Client { return $this->client; }
    public function getProject(): ?Project { return $this->project; }
    public function getDeal(): ?Deal { return $this->deal; }
    public function getVersionCount(): int { return $this->versionCount; }
    public function getCheckedOutBy(): ?User { return $this->checkedOutBy; }
    public function getCheckedOutAt(): ?\DateTimeImmutable { return $this->checkedOutAt; }
    public function getSearchText(): string { return $this->searchText; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function getArchivedAt(): ?\DateTimeImmutable { return $this->archivedAt; }

    /** @return list<User> */
    public function getReaders(): array
    {
        return array_values($this->readers->toArray());
    }

    public function hasReader(User $user): bool
    {
        foreach ($this->readers as $reader) {
            if ($reader->getId() === $user->getId()) {
                return true;
            }
        }

        return false;
    }

    public function isEditable(): bool
    {
        return in_array($this->status, self::EDITABLE, true);
    }

    public function isRegistered(): bool
    {
        return in_array($this->status, self::REGISTERED_STATES, true);
    }

    public function retitle(string $title): void
    {
        $title = trim($title);
        if ($title === '' || mb_strlen($title) > 255) {
            throw new \InvalidArgumentException('Document title is required (at most 255 characters).');
        }
        $this->assertEditable();
        $this->title = $title;
        $this->touch();
    }

    /** @param list<string> $tags */
    public function describe(?string $summary, ?OrgUnit $ownerUnit, string $confidentiality, array $tags): void
    {
        $this->assertEditable();
        if (!in_array($confidentiality, self::CONFIDENTIALITY_LEVELS, true)) {
            throw new \InvalidArgumentException('Confidentiality must be general or confidential.');
        }
        if ($ownerUnit?->isArchived() && $ownerUnit !== $this->ownerUnit) {
            throw new \DomainException('An archived unit cannot own new documents.');
        }
        $clean = [];
        foreach ($tags as $tag) {
            $tag = mb_strtolower(trim((string) $tag));
            if ($tag === '') {
                continue;
            }
            if (mb_strlen($tag) > 40) {
                throw new \InvalidArgumentException('Tags are at most 40 characters.');
            }
            $clean[$tag] = $tag;
        }
        if (count($clean) > 20) {
            throw new \InvalidArgumentException('A document has at most 20 tags.');
        }
        $summary = $summary !== null ? trim($summary) : null;
        if ($summary !== null && mb_strlen($summary) > 5000) {
            throw new \InvalidArgumentException('The summary is at most 5000 characters.');
        }
        $this->summary = $summary === '' ? null : $summary;
        $this->ownerUnit = $ownerUnit;
        $this->confidentiality = $confidentiality;
        $this->tags = array_values($clean);
        $this->touch();
    }

    public function schedule(?\DateTimeImmutable $effectiveOn, ?\DateTimeImmutable $expiresOn, ?\DateTimeImmutable $reviewOn): void
    {
        $this->assertEditable();
        if ($effectiveOn !== null && $expiresOn !== null && $expiresOn < $effectiveOn) {
            throw new \InvalidArgumentException('A document cannot expire before it takes effect.');
        }
        $this->effectiveOn = $effectiveOn?->setTime(0, 0);
        $this->expiresOn = $expiresOn?->setTime(0, 0);
        $this->reviewOn = $reviewOn?->setTime(0, 0);
        $this->touch();
    }

    public function relateTo(?Client $client, ?Project $project, ?Deal $deal): void
    {
        $this->assertEditable();
        $this->client = $client;
        $this->project = $project;
        $this->deal = $deal;
        $this->touch();
    }

    /** @param list<User> $readers */
    public function replaceReaders(array $readers): void
    {
        if ($this->status === self::ARCHIVED) {
            throw new \DomainException('Access to an archived document can no longer be changed.');
        }
        $this->readers->clear();
        foreach ($readers as $reader) {
            if ($reader->getId() !== $this->author->getId() && !$this->hasReader($reader)) {
                $this->readers->add($reader);
            }
        }
        $this->touch();
    }

    /** Next version number. A new file on an approved document sends it back to draft: its approval no longer covers the content. */
    public function addVersion(): int
    {
        $this->assertEditable();
        if ($this->status === self::APPROVED) {
            $this->status = self::DRAFT;
        }
        $this->touch();

        return ++$this->versionCount;
    }

    public function checkOut(User $user): void
    {
        $this->assertEditable();
        $this->checkedOutBy = $user;
        $this->checkedOutAt = new \DateTimeImmutable();
    }

    public function checkIn(): void
    {
        $this->checkedOutBy = null;
        $this->checkedOutAt = null;
    }

    public function isLockedFor(User $user): bool
    {
        return $this->checkedOutBy !== null && $this->checkedOutBy->getId() !== $user->getId();
    }

    /** Fields of the type's required list that are still empty. @return list<string> */
    public function missingRequiredFields(): array
    {
        $values = [
            'summary' => $this->summary, 'owner_unit' => $this->ownerUnit, 'effective_on' => $this->effectiveOn,
            'expires_on' => $this->expiresOn, 'review_on' => $this->reviewOn, 'client' => $this->client, 'project' => $this->project,
        ];

        return array_values(array_filter($this->type->getRequiredFields(), static fn (string $field): bool => ($values[$field] ?? null) === null));
    }

    public function canBeRegistered(): bool
    {
        return $this->status === self::APPROVED || ($this->status === self::DRAFT && !$this->type->requiresApproval());
    }

    /** States from which a document can be sent (again) for approval. */
    public function canBeSubmitted(): bool
    {
        return in_array($this->status, [self::DRAFT, self::REJECTED, self::WITHDRAWN], true);
    }

    public function submitForApproval(): void
    {
        if (!$this->canBeSubmitted()) {
            throw new \DomainException('Only a draft, rejected or withdrawn document can be sent for approval.');
        }
        if ($this->versionCount === 0) {
            throw new \DomainException('Upload the document file before sending it for approval.');
        }
        if ($this->checkedOutBy !== null) {
            throw new \DomainException('The document is checked out; check it in before sending it for approval.');
        }
        $missing = $this->missingRequiredFields();
        if ($missing !== []) {
            throw new \DomainException('Fill in the required fields first: '.implode(', ', $missing).'.');
        }
        $this->status = self::IN_APPROVAL;
        $this->touch();
    }

    /** Applies the outcome of an approval route: approved, rejected, returned (back to draft) or withdrawn. */
    public function concludeApproval(string $routeStatus): void
    {
        if ($this->status !== self::IN_APPROVAL) {
            throw new \DomainException('The document is not in approval.');
        }
        $this->status = match ($routeStatus) {
            ApprovalRoute::APPROVED => self::APPROVED,
            ApprovalRoute::REJECTED => self::REJECTED,
            ApprovalRoute::RETURNED => self::DRAFT,
            ApprovalRoute::WITHDRAWN => self::WITHDRAWN,
            default => throw new \LogicException('Unknown route outcome.'),
        };
        $this->touch();
    }

    /** @throws \DomainException when the document cannot be registered yet, with the reason */
    public function assertRegistrable(): void
    {
        if (!$this->canBeRegistered()) {
            throw new \DomainException($this->type->requiresApproval() && $this->status !== self::APPROVED
                ? 'This document type must be approved before registration.'
                : 'Only a draft or approved document can be registered.');
        }
        if ($this->versionCount === 0) {
            throw new \DomainException('Upload the document file before registration.');
        }
        if ($this->checkedOutBy !== null) {
            throw new \DomainException('The document is checked out; check it in before registration.');
        }
        $missing = $this->missingRequiredFields();
        if ($missing !== []) {
            throw new \DomainException('Fill in the required fields first: '.implode(', ', $missing).'.');
        }
    }

    /** Freezes the document under its number; it is in force at once unless its effective date is still ahead. */
    public function register(string $number, string $registrar, \DateTimeImmutable $now): void
    {
        $this->assertRegistrable();
        $this->registrationNumber = $number;
        $this->registeredAt = $now;
        $this->registeredBy = $registrar;
        $this->status = $this->effectiveOn === null || $this->effectiveOn->format('Y-m-d') <= $now->format('Y-m-d') ? self::IN_FORCE : self::REGISTERED;
        $this->touch();
    }

    public function archive(): void
    {
        if (!in_array($this->status, [self::REGISTERED, self::IN_FORCE, self::SUPERSEDED], true)) {
            throw new \DomainException('Only registered documents are archived; delete an unregistered draft instead.');
        }
        $this->status = self::ARCHIVED;
        $this->archivedAt = new \DateTimeImmutable();
        $this->touch();
    }

    public function isDeletable(): bool
    {
        return $this->registrationNumber === null && in_array($this->status, [self::DRAFT, self::REJECTED, self::WITHDRAWN], true);
    }

    public function refreshSearchText(?string $versionText): void
    {
        $tags = implode(' ', array_map(static fn (string $tag): string => '[tag:'.$tag.']', $this->tags));
        $parts = [$this->registrationNumber, $this->title, $this->summary, $tags, $this->type->getName(), $versionText];
        $text = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', implode(' ', array_filter($parts, static fn (?string $part): bool => $part !== null && $part !== '')))));
        $this->searchText = mb_substr($text, 0, 200000);
    }

    private function assertEditable(): void
    {
        if (isset($this->title) && !$this->isEditable()) {
            throw new \DomainException('The document can no longer be edited in its current state.');
        }
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
