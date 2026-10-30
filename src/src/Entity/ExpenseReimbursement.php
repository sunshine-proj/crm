<?php

namespace App\Entity;

use App\Repository\ExpenseReimbursementRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ExpenseReimbursementRepository::class)]
#[ORM\Table(name: 'expense_reimbursement')]
#[ORM\Index(columns: ['expense_id', 'paid_at'], name: 'idx_expense_reimbursement_expense_paid')]
class ExpenseReimbursement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Expense::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Expense $expense;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $recordedBy;

    #[ORM\Column(type: Types::BIGINT)]
    private int $amountMinor;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $paidAt;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $reference;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Expense $expense, User $recordedBy, int $amountMinor, \DateTimeImmutable $paidAt, ?string $reference)
    {
        if ($amountMinor < 1) {
            throw new \InvalidArgumentException('Reimbursement amount must be positive.');
        }
        $this->expense = $expense;
        $this->recordedBy = $recordedBy;
        $this->amountMinor = $amountMinor;
        $this->paidAt = $paidAt;
        $this->reference = $reference;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getExpense(): Expense { return $this->expense; }
    public function getRecordedBy(): User { return $this->recordedBy; }
    public function getAmountMinor(): int { return $this->amountMinor; }
    public function getPaidAt(): \DateTimeImmutable { return $this->paidAt; }
    public function getReference(): ?string { return $this->reference; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
