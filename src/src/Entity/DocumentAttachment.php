<?php

namespace App\Entity;

use App\Repository\DocumentAttachmentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** A supporting file (annex, scan, spreadsheet) kept beside the main document versions. */
#[ORM\Entity(repositoryClass: DocumentAttachmentRepository::class)]
#[ORM\Table(name: 'document_attachment')]
#[ORM\Index(columns: ['document_id'], name: 'idx_document_attachment_document')]
class DocumentAttachment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Document::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Document $document;

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

    #[ORM\Column(length: 180)]
    private string $uploadedByEmail;

    /** Set when the file was destroyed by a disposal; the metadata and SHA-256 stay as evidence. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $fileDisposedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Document $document, StoredFile $file, User $uploadedBy)
    {
        $this->document = $document;
        $this->originalName = $file->originalName;
        $this->storedName = $file->storedName;
        $this->mimeType = $file->mimeType;
        $this->sizeBytes = $file->sizeBytes;
        $this->sha256 = $file->sha256;
        $this->uploadedByEmail = $uploadedBy->getUserIdentifier();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getDocument(): Document { return $this->document; }
    public function getOriginalName(): string { return $this->originalName; }
    public function getStoredName(): string { return $this->storedName; }
    public function getMimeType(): string { return $this->mimeType; }
    public function getSizeBytes(): int { return $this->sizeBytes; }
    public function getSha256(): string { return $this->sha256; }
    public function getUploadedByEmail(): string { return $this->uploadedByEmail; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getFileDisposedAt(): ?\DateTimeImmutable { return $this->fileDisposedAt; }

    public function discardContent(\DateTimeImmutable $now): void
    {
        $this->fileDisposedAt = $now;
    }
}
