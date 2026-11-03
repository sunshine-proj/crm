<?php

namespace App\Entity;

use App\Repository\PurchaseOrderApprovalRepository;
use Doctrine\ORM\Mapping as ORM;

/** Append-only approval decisions for purchase orders that exceed the approval threshold. */
#[ORM\Entity(repositoryClass: PurchaseOrderApprovalRepository::class)]
#[ORM\Table(name: 'purchase_order_approval')]
#[ORM\Index(columns: ['purchase_order_id', 'created_at'], name: 'idx_purchase_order_approval_history')]
class PurchaseOrderApproval
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PurchaseOrder::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private PurchaseOrder $purchaseOrder;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $actor;

    #[ORM\Column(length: 16)]
    private string $decision;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $comment;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(PurchaseOrder $purchaseOrder, User $actor, string $decision, ?string $comment)
    {
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            throw new \InvalidArgumentException('Decision must be approved or rejected.');
        }
        $this->purchaseOrder = $purchaseOrder;
        $this->actor = $actor;
        $this->decision = $decision;
        $this->comment = $comment;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getActor(): User { return $this->actor; }
    public function getDecision(): string { return $this->decision; }
    public function getComment(): ?string { return $this->comment; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
