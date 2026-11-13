<?php

namespace App\Entity;

use App\Repository\DocumentDisposalRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Four-eyes disposal of an archived document past its retention period: a registry clerk proposes it, a different
 * administrator approves or rejects it. Approval destroys the files and content; this record keeps the manifest of
 * what was destroyed (versions and attachments with their SHA-256) and the retention basis, as the tombstone.
 */
#[ORM\Entity(repositoryClass: DocumentDisposalRepository::class)]
#[ORM\Table(name: 'document_disposal')]
#[ORM\Index(columns: ['status', 'proposed_at'], name: 'idx_document_disposal_status')]
class DocumentDisposal
{
    public const PROPOSED = 'proposed';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Document::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Document $document;

    #[ORM\Column(length: 16)]
    private string $status = self::PROPOSED;

    #[ORM\Column(length: 180)]
    private string $proposedBy;

    #[ORM\Column]
    private \DateTimeImmutable $proposedAt;

    #[ORM\Column(type: Types::TEXT)]
    private string $reason;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $retainUntil;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $decidedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $decisionComment = null;

    /** @var array<string, mixed> what was destroyed, filled on approval */
    #[ORM\Column(type: Types::JSON)]
    private array $manifest = [];

    public function __construct(Document $document, string $proposedBy, string $reason, \DateTimeImmutable $now)
    {
        if (!$document->isDueForDisposal($now)) {
            throw new \DomainException($document->isOnLegalHold() ? 'The document is under legal hold.' : 'Only archived documents past their retention period can be proposed for disposal.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('Give the reason or the disposal schedule this follows.');
        }
        $this->document = $document;
        $this->proposedBy = $proposedBy;
        $this->reason = mb_substr($reason, 0, 4000);
        $this->proposedAt = $now;
        $this->retainUntil = $document->retainUntil() ?? throw new \LogicException('A document due for disposal has a retention end.');
    }

    public function getId(): ?int { return $this->id; }
    public function getDocument(): Document { return $this->document; }
    public function getStatus(): string { return $this->status; }
    public function isOpen(): bool { return $this->status === self::PROPOSED; }
    public function getProposedBy(): string { return $this->proposedBy; }
    public function getProposedAt(): \DateTimeImmutable { return $this->proposedAt; }
    public function getReason(): string { return $this->reason; }
    public function getRetainUntil(): \DateTimeImmutable { return $this->retainUntil; }
    public function getDecidedBy(): ?string { return $this->decidedBy; }
    public function getDecidedAt(): ?\DateTimeImmutable { return $this->decidedAt; }
    public function getDecisionComment(): ?string { return $this->decisionComment; }
    /** @return array<string, mixed> */
    public function getManifest(): array { return $this->manifest; }

    /** @param array<string, mixed> $manifest */
    public function approve(string $by, ?string $comment, array $manifest, \DateTimeImmutable $now): void
    {
        $this->assertDecidableBy($by);
        $this->status = self::APPROVED;
        $this->decide($by, $comment, $now);
        $this->manifest = $manifest;
    }

    public function reject(string $by, ?string $comment, \DateTimeImmutable $now, bool $automatic = false): void
    {
        if (!$automatic) {
            $this->assertDecidableBy($by);
            if (trim((string) $comment) === '') {
                throw new \InvalidArgumentException('Say why the disposal is rejected.');
            }
        } elseif (!$this->isOpen()) {
            throw new \DomainException('This disposal has already been decided.');
        }
        $this->status = self::REJECTED;
        $this->decide($by, $comment, $now);
    }

    private function assertDecidableBy(string $by): void
    {
        if (!$this->isOpen()) {
            throw new \DomainException('This disposal has already been decided.');
        }
        if (strcasecmp($by, $this->proposedBy) === 0) {
            throw new \DomainException('A disposal must be decided by someone other than the person who proposed it.');
        }
    }

    private function decide(string $by, ?string $comment, \DateTimeImmutable $now): void
    {
        $comment = $comment !== null ? trim($comment) : null;
        $this->decidedBy = $by;
        $this->decidedAt = $now;
        $this->decisionComment = $comment === '' ? null : $comment;
    }
}
