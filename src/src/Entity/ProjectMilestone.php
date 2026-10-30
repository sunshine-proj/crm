<?php

namespace App\Entity;

use App\Repository\ProjectMilestoneRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProjectMilestoneRepository::class)]
#[ORM\Table(name: 'project_milestone')]
#[ORM\Index(columns: ['project_id', 'due_date'], name: 'idx_project_milestone_project_due')]
class ProjectMilestone
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Project $project;

    #[ORM\ManyToOne(targetEntity: Invoice::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Invoice $invoice = null;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(type: Types::TEXT)]
    private string $description;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dueDate;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $amountMinor;

    #[ORM\Column(length: 3, nullable: true)]
    private ?string $currency;

    #[ORM\Column(length: 20, options: ['default' => 'planned'])]
    private string $status = 'planned';

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Project $project, string $title, string $description, ?\DateTimeImmutable $dueDate, ?int $amountMinor, ?string $currency)
    {
        if (trim($title) === '' || ($amountMinor !== null && $amountMinor < 1) || (($amountMinor === null) !== ($currency === null))) {
            throw new \InvalidArgumentException('Milestone requires a title and an optional positive amount and currency.');
        }
        $this->project = $project;
        $this->title = trim($title);
        $this->description = trim($description);
        $this->dueDate = $dueDate;
        $this->amountMinor = $amountMinor;
        $this->currency = $currency;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getProject(): Project { return $this->project; }
    public function getInvoice(): ?Invoice { return $this->invoice; }
    public function setInvoice(?Invoice $invoice): static { $this->invoice = $invoice; return $this; }
    public function getTitle(): string { return $this->title; }
    public function getDescription(): string { return $this->description; }
    public function getDueDate(): ?\DateTimeImmutable { return $this->dueDate; }
    public function getAmountMinor(): ?int { return $this->amountMinor; }
    public function getCurrency(): ?string { return $this->currency; }
    public function getStatus(): string { return $this->status; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function complete(): static
    {
        if ($this->status === 'invoiced') {
            throw new \DomainException('An invoiced milestone cannot change its status.');
        }
        $this->status = 'completed';

        return $this;
    }

    public function markInvoiced(Invoice $invoice): static
    {
        if ($this->status !== 'completed' || $this->invoice !== null || $this->amountMinor === null || $this->currency === null) {
            throw new \DomainException('Only completed, priced milestones without an invoice may be invoiced.');
        }
        $this->status = 'invoiced';
        $this->invoice = $invoice;

        return $this;
    }
}
