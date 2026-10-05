<?php

namespace App\Entity;

use App\Repository\ProjectDocumentRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProjectDocumentRepository::class)]
#[ORM\Table(name: 'project_document')]
#[ORM\UniqueConstraint(name: 'UNIQ_PROJECT_DOCUMENT_STORED_NAME', columns: ['stored_name'])]
#[ORM\Index(columns: ['project_id'], name: 'idx_project_document_project')]
#[ORM\Index(columns: ['project_id', 'created_at'], name: 'idx_project_document_project_date')]
#[ORM\Index(columns: ['uploaded_by_id'], name: 'idx_project_document_uploader')]
class ProjectDocument
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Project $project;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $uploadedBy;

    #[ORM\Column(length: 255)]
    private string $originalName;

    #[ORM\Column(length: 64)]
    private string $storedName;

    #[ORM\Column(length: 127)]
    private string $mimeType;

    #[ORM\Column(type: 'bigint')]
    private int $sizeBytes;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Project $project, ?User $uploadedBy, string $originalName, string $storedName, string $mimeType, int $sizeBytes)
    {
        $this->project = $project;
        $this->uploadedBy = $uploadedBy;
        $this->originalName = $originalName;
        $this->storedName = $storedName;
        $this->mimeType = $mimeType;
        $this->sizeBytes = $sizeBytes;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getProject(): Project { return $this->project; }
    public function getUploadedBy(): ?User { return $this->uploadedBy; }
    public function getOriginalName(): string { return $this->originalName; }
    public function getStoredName(): string { return $this->storedName; }
    public function getMimeType(): string { return $this->mimeType; }
    public function getSizeBytes(): int { return $this->sizeBytes; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}