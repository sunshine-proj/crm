<?php

namespace App\Entity;

use App\Repository\DocumentLinkRepository;
use Doctrine\ORM\Mapping as ORM;

/** A typed relation from one document to another: it replaces, amends or refers to the target. */
#[ORM\Entity(repositoryClass: DocumentLinkRepository::class)]
#[ORM\Table(name: 'document_link')]
#[ORM\UniqueConstraint(name: 'uniq_document_link', columns: ['source_id', 'target_id', 'kind'])]
#[ORM\Index(columns: ['target_id'], name: 'idx_document_link_target')]
class DocumentLink
{
    public const KINDS = ['replaces', 'amends', 'refers_to'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Document::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Document $source;

    #[ORM\ManyToOne(targetEntity: Document::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Document $target;

    #[ORM\Column(length: 16)]
    private string $kind;

    #[ORM\Column(length: 180)]
    private string $createdBy;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Document $source, Document $target, string $kind, string $createdBy)
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('Link kind must be replaces, amends or refers_to.');
        }
        if ($source === $target) {
            throw new \InvalidArgumentException('A document cannot be linked to itself.');
        }
        $this->source = $source;
        $this->target = $target;
        $this->kind = $kind;
        $this->createdBy = $createdBy;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getSource(): Document { return $this->source; }
    public function getTarget(): Document { return $this->target; }
    public function getKind(): string { return $this->kind; }
    public function getCreatedBy(): string { return $this->createdBy; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
