<?php

namespace App\Entity;

use App\Repository\DocumentCommentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** A remark on a specific version, optionally quoting the passage it is about; resolved rather than deleted. */
#[ORM\Entity(repositoryClass: DocumentCommentRepository::class)]
#[ORM\Table(name: 'document_comment')]
#[ORM\Index(columns: ['document_id', 'version_number'], name: 'idx_document_comment_version')]
class DocumentComment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Document::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Document $document;

    #[ORM\Column]
    private int $versionNumber;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $author;

    #[ORM\Column(length: 180)]
    private string $authorEmail;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $quote;

    #[ORM\Column(type: Types::TEXT)]
    private string $body;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $resolvedBy = null;

    public function __construct(Document $document, int $versionNumber, User $author, ?string $quote, string $body)
    {
        $body = trim($body);
        $quote = $quote !== null ? trim($quote) : null;
        if ($body === '' || mb_strlen($body) > 4000) {
            throw new \InvalidArgumentException('A comment needs text (at most 4000 characters).');
        }
        if ($quote !== null && mb_strlen($quote) > 500) {
            throw new \InvalidArgumentException('The quoted passage is at most 500 characters.');
        }
        $this->document = $document;
        $this->versionNumber = $versionNumber;
        $this->author = $author;
        $this->authorEmail = $author->getUserIdentifier();
        $this->quote = $quote === '' ? null : $quote;
        $this->body = $body;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getDocument(): Document { return $this->document; }
    public function getVersionNumber(): int { return $this->versionNumber; }
    public function getAuthor(): ?User { return $this->author; }
    public function getAuthorEmail(): string { return $this->authorEmail; }
    public function getQuote(): ?string { return $this->quote; }
    public function getBody(): string { return $this->body; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getResolvedAt(): ?\DateTimeImmutable { return $this->resolvedAt; }
    public function getResolvedBy(): ?string { return $this->resolvedBy; }

    public function resolve(string $actor): void
    {
        if ($this->resolvedAt !== null) {
            throw new \DomainException('The comment is already resolved.');
        }
        $this->resolvedAt = new \DateTimeImmutable();
        $this->resolvedBy = $actor;
    }
}
