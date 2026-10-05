<?php

namespace App\Entity;

use App\Repository\PaymentRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PaymentRepository::class)]
#[ORM\Table(name: 'payment')]
class Payment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Invoice::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Invoice $invoice;

    #[ORM\Column(type: 'bigint')]
    private int $amountMinor;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $reference = null;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $paidAt;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Invoice $invoice, int $amountMinor, \DateTimeImmutable $paidAt, ?string $reference)
    {
        $this->invoice = $invoice;
        $this->amountMinor = $amountMinor;
        $this->paidAt = $paidAt;
        $this->reference = $reference;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getInvoice(): Invoice { return $this->invoice; }
    public function getAmountMinor(): int { return $this->amountMinor; }
    public function getReference(): ?string { return $this->reference; }
    public function getPaidAt(): \DateTimeImmutable { return $this->paidAt; }
}