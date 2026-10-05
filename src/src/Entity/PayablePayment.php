<?php

namespace App\Entity;

use App\Repository\PayablePaymentRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PayablePaymentRepository::class)]
#[ORM\Table(name: 'payable_payment')]
#[ORM\Index(columns: ['bill_id', 'paid_at'], name: 'idx_payable_payment_bill_paid_at')]
class PayablePayment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PayableBill::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private PayableBill $bill;

    #[ORM\Column(type: 'bigint')]
    private int $amountMinor;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $reference;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $paidAt;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(PayableBill $bill, int $amountMinor, \DateTimeImmutable $paidAt, ?string $reference)
    {
        $this->bill = $bill;
        $this->amountMinor = $amountMinor;
        $this->paidAt = $paidAt;
        $this->reference = $reference;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getBill(): PayableBill { return $this->bill; }
    public function getAmountMinor(): int { return $this->amountMinor; }
    public function getPaidAt(): \DateTimeImmutable { return $this->paidAt; }
    public function getReference(): ?string { return $this->reference; }
}