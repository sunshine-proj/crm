<?php

namespace App\Entity;

use App\Repository\ExpenseApprovalRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ExpenseApprovalRepository::class)]
#[ORM\Table(name: 'expense_approval')]
#[ORM\Index(columns: ['expense_id'], name: 'idx_expense_approval_expense')]
#[ORM\Index(columns: ['expense_id', 'created_at'], name: 'idx_expense_approval_history')]
#[ORM\Index(columns: ['actor_id'], name: 'idx_expense_approval_actor')]
class ExpenseApproval
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Expense::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Expense $expense;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $actor;

    #[ORM\Column(length: 16)]
    private string $decision;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $comment;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Expense $expense, User $actor, string $decision, ?string $comment)
    {
        $this->expense = $expense;
        $this->actor = $actor;
        $this->decision = $decision;
        $this->comment = $comment;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getExpense(): Expense { return $this->expense; }
    public function getActor(): User { return $this->actor; }
    public function getDecision(): string { return $this->decision; }
    public function getComment(): ?string { return $this->comment; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}