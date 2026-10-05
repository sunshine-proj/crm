<?php

namespace App\Entity;

use App\Repository\PayableBillRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PayableBillRepository::class)]
#[ORM\Table(name: 'payable_bill')]
#[ORM\Index(columns: ['due_date'], name: 'idx_payable_bill_due_date')]
#[ORM\UniqueConstraint(name: 'UNIQ_PAYABLE_VENDOR_REFERENCE', columns: ['vendor_name', 'vendor_reference'])]
class PayableBill
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $vendorName;

    #[ORM\Column(length: 80)]
    private string $vendorReference;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(type: Types::BIGINT)]
    private int $totalMinor;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $billDate;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dueDate;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $vendorName, string $vendorReference, string $currency, int $totalMinor, \DateTimeImmutable $billDate, ?\DateTimeImmutable $dueDate)
    {
        $this->vendorName = $vendorName;
        $this->vendorReference = $vendorReference;
        $this->currency = $currency;
        $this->totalMinor = $totalMinor;
        $this->billDate = $billDate;
        $this->dueDate = $dueDate;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getVendorName(): string { return $this->vendorName; }
    public function getVendorReference(): string { return $this->vendorReference; }
    public function getCurrency(): string { return $this->currency; }
    public function getTotalMinor(): int { return $this->totalMinor; }
    public function getBillDate(): \DateTimeImmutable { return $this->billDate; }
    public function getDueDate(): ?\DateTimeImmutable { return $this->dueDate; }
}