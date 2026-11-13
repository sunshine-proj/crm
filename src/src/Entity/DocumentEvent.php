<?php

namespace App\Entity;

use App\Repository\DocumentEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Append-only trail of what happened to a document and who did it (also on behalf of whom, for deputies). */
#[ORM\Entity(repositoryClass: DocumentEventRepository::class)]
#[ORM\Table(name: 'document_event')]
#[ORM\Index(columns: ['document_id', 'created_at'], name: 'idx_document_event_document')]
class DocumentEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Document::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Document $document;

    #[ORM\Column(length: 180)]
    private string $actor;

    #[ORM\Column(length: 40)]
    private string $action;

    /** @var array<string, scalar|null> */
    #[ORM\Column(type: Types::JSON)]
    private array $details;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** @param array<string, scalar|null> $details */
    public function __construct(Document $document, string $actor, string $action, array $details = [])
    {
        $this->document = $document;
        $this->actor = $actor;
        $this->action = $action;
        $this->details = $details;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getDocument(): Document { return $this->document; }
    public function getActor(): string { return $this->actor; }
    public function getAction(): string { return $this->action; }
    /** @return array<string, scalar|null> */
    public function getDetails(): array { return $this->details; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
