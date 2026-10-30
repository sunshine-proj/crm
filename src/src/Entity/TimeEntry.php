<?php

namespace App\Entity;

use App\Repository\TimeEntryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TimeEntryRepository::class)]
#[ORM\Table(name: 'time_entry')]
#[ORM\Index(columns: ['project_id'], name: 'idx_time_entry_project')]
#[ORM\Index(columns: ['project_id', 'work_date'], name: 'idx_time_entry_project_date')]
#[ORM\Index(columns: ['task_id'], name: 'idx_time_entry_task')]
#[ORM\Index(columns: ['user_id'], name: 'idx_time_entry_user')]
class TimeEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Project $project;

    #[ORM\ManyToOne(targetEntity: Task::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Task $task;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Invoice::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Invoice $invoice = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $workDate;

    #[ORM\Column]
    private int $durationMinutes;

    #[ORM\Column]
    private bool $billable;

    #[ORM\Column(length: 255)]
    private string $description;

    #[ORM\Column(length: 24, options: ['default' => 'pending_approval'])]
    private string $approvalStatus = 'pending_approval';

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $decidedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $decisionComment = null;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $costMinor = null;

    #[ORM\Column(length: 3, nullable: true)]
    private ?string $costCurrency = null;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $billingAmountMinor = null;

    #[ORM\Column(length: 3, nullable: true)]
    private ?string $billingCurrency = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Project $project,
        ?Task $task,
        User $user,
        \DateTimeImmutable $workDate,
        int $durationMinutes,
        bool $billable,
        string $description,
        ?int $costMinor = null,
        ?string $costCurrency = null,
        ?int $billingAmountMinor = null,
        ?string $billingCurrency = null,
    )
    {
        if ($durationMinutes < 1 || trim($description) === '') {
            throw new \InvalidArgumentException('Time entry requires positive duration and a description.');
        }
        $this->project = $project;
        $this->task = $task;
        $this->user = $user;
        $this->workDate = $workDate;
        $this->durationMinutes = $durationMinutes;
        $this->billable = $billable;
        $this->description = trim($description);
        $this->costMinor = $costMinor;
        $this->costCurrency = $costCurrency;
        $this->billingAmountMinor = $billingAmountMinor;
        $this->billingCurrency = $billingCurrency;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getProject(): Project { return $this->project; }
    public function getTask(): ?Task { return $this->task; }
    public function getUser(): User { return $this->user; }
    public function getWorkDate(): \DateTimeImmutable { return $this->workDate; }
    public function getDurationMinutes(): int { return $this->durationMinutes; }
    public function isBillable(): bool { return $this->billable; }
    public function getDescription(): string { return $this->description; }
    public function getInvoice(): ?Invoice { return $this->invoice; }
    public function setInvoice(?Invoice $invoice): static { $this->invoice = $invoice; return $this; }
    public function getApprovalStatus(): string { return $this->approvalStatus; }
    public function getDecidedBy(): ?User { return $this->decidedBy; }
    public function getDecidedAt(): ?\DateTimeImmutable { return $this->decidedAt; }
    public function getDecisionComment(): ?string { return $this->decisionComment; }
    public function getCostMinor(): ?int { return $this->costMinor; }
    public function getCostCurrency(): ?string { return $this->costCurrency; }
    public function getBillingAmountMinor(): ?int { return $this->billingAmountMinor; }
    public function getBillingCurrency(): ?string { return $this->billingCurrency; }

    public function decide(string $status, User $actor, ?string $comment = null): static
    {
        if ($this->approvalStatus !== 'pending_approval' || !in_array($status, ['approved', 'rejected'], true)) {
            throw new \DomainException('Time-entry approval transition is not allowed.');
        }
        $this->approvalStatus = $status;
        $this->decidedBy = $actor;
        $this->decidedAt = new \DateTimeImmutable();
        $this->decisionComment = $comment;

        return $this;
    }
}