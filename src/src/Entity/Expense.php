<?php

namespace App\Entity;

use App\Repository\ExpenseRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ExpenseRepository::class)]
#[ORM\Table(name: 'expense')]
#[ORM\Index(columns: ['project_id'], name: 'idx_expense_project')]
#[ORM\Index(columns: ['project_id', 'expense_date'], name: 'idx_expense_project_date')]
#[ORM\Index(columns: ['submitter_id'], name: 'idx_expense_submitter')]
class Expense
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Project $project;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $submitter;

    #[ORM\Column(length: 255)]
    private string $description;

    #[ORM\Column(type: Types::BIGINT)]
    private int $amountMinor;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $expenseDate;

    #[ORM\Column(length: 24)]
    private string $status = 'pending_approval';

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Project $project, User $submitter, string $description, int $amountMinor, string $currency, \DateTimeImmutable $expenseDate)
    {
        if (trim($description) === '' || $amountMinor < 1) {
            throw new \InvalidArgumentException('Expense requires a description and positive amount.');
        }
        $this->project = $project;
        $this->submitter = $submitter;
        $this->description = trim($description);
        $this->amountMinor = $amountMinor;
        $this->currency = $currency;
        $this->expenseDate = $expenseDate;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int { return $this->id; }
    public function getProject(): Project { return $this->project; }
    public function getSubmitter(): User { return $this->submitter; }
    public function getDescription(): string { return $this->description; }
    public function getAmountMinor(): int { return $this->amountMinor; }
    public function getCurrency(): string { return $this->currency; }
    public function getExpenseDate(): \DateTimeImmutable { return $this->expenseDate; }
    public function getStatus(): string { return $this->status; }

    public function decide(string $status): static
    {
        if ($this->status !== 'pending_approval' || !in_array($status, ['approved', 'rejected'], true)) {
            throw new \DomainException('Expense approval transition is not allowed.');
        }
        $this->status = $status;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }
}