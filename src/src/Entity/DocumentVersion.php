<?php

namespace App\Entity;

use App\Repository\DocumentVersionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** One immutable file revision of a document. The SHA-256 of the content identifies exactly what was approved or read. */
#[ORM\Entity(repositoryClass: DocumentVersionRepository::class)]
#[ORM\Table(name: 'document_version')]
#[ORM\UniqueConstraint(name: 'uniq_document_version_number', columns: ['document_id', 'number'])]
class DocumentVersion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Document::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Document $document;

    #[ORM\Column]
    private int $number;

    #[ORM\Column(length: 255)]
    private string $originalName;

    #[ORM\Column(length: 64)]
    private string $storedName;

    #[ORM\Column(length: 120)]
    private string $mimeType;

    #[ORM\Column]
    private int $sizeBytes;

    #[ORM\Column(length: 64)]
    private string $sha256;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $uploadedBy;

    #[ORM\Column(length: 180)]
    private string $uploadedByEmail;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $comment;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $extractedText;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Document $document, int $number, StoredFile $file, User $uploadedBy, ?string $comment, ?string $extractedText)
    {
        $comment = $comment !== null ? trim($comment) : null;
        if ($comment !== null && mb_strlen($comment) > 500) {
            throw new \InvalidArgumentException('The version comment is at most 500 characters.');
        }
        $this->document = $document;
        $this->number = $number;
        $this->originalName = $file->originalName;
        $this->storedName = $file->storedName;
        $this->mimeType = $file->mimeType;
        $this->sizeBytes = $file->sizeBytes;
        $this->sha256 = $file->sha256;
        $this->uploadedBy = $uploadedBy;
        $this->uploadedByEmail = $uploadedBy->getUserIdentifier();
        $this->comment = $comment === '' ? null : $comment;
        $this->extractedText = $extractedText;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getDocument(): Document { return $this->document; }
    public function getNumber(): int { return $this->number; }
    public function getOriginalName(): string { return $this->originalName; }
    public function getStoredName(): string { return $this->storedName; }
    public function getMimeType(): string { return $this->mimeType; }
    public function getSizeBytes(): int { return $this->sizeBytes; }
    public function getSha256(): string { return $this->sha256; }
    public function getUploadedByEmail(): string { return $this->uploadedByEmail; }
    public function getComment(): ?string { return $this->comment; }
    public function getExtractedText(): ?string { return $this->extractedText; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
