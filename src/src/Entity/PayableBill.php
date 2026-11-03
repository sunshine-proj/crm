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

    #[ORM\ManyToOne(targetEntity: PurchaseOrder::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?PurchaseOrder $purchaseOrder = null;

    /** unmatched (no purchase order), matched, or exception (accepted with a recorded reason) */
    #[ORM\Column(length: 16, options: ['default' => 'unmatched'])]
    private string $matchStatus = 'unmatched';

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $matchNote = null;

    /** Goods-received-not-invoiced accrual cleared by this bill. */
    #[ORM\Column(type: Types::BIGINT, options: ['default' => 0])]
    private int $accrualClearedMinor = 0;

    /** Billed amount above the accrual, expensed as purchase price variance (negative when under-billed). */
    #[ORM\Column(type: Types::BIGINT, options: ['default' => 0])]
    private int $priceVarianceMinor = 0;

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

    public function matchTo(PurchaseOrder $order, string $status, ?string $note, int $accrualClearedMinor, int $priceVarianceMinor): static
    {
        if (!in_array($status, ['matched', 'exception'], true)) {
            throw new \InvalidArgumentException('Unsupported match status.');
        }
        $this->purchaseOrder = $order;
        $this->matchStatus = $status;
        $this->matchNote = $note;
        $this->accrualClearedMinor = $accrualClearedMinor;
        $this->priceVarianceMinor = $priceVarianceMinor;

        return $this;
    }

    public function getPurchaseOrder(): ?PurchaseOrder { return $this->purchaseOrder; }
    public function getMatchStatus(): string { return $this->matchStatus; }
    public function getMatchNote(): ?string { return $this->matchNote; }
    public function getAccrualClearedMinor(): int { return $this->accrualClearedMinor; }
    public function getPriceVarianceMinor(): int { return $this->priceVarianceMinor; }
    public function getId(): ?int { return $this->id; }
    public function getVendorName(): string { return $this->vendorName; }
    public function getVendorReference(): string { return $this->vendorReference; }
    public function getCurrency(): string { return $this->currency; }
    public function getTotalMinor(): int { return $this->totalMinor; }
    public function getBillDate(): \DateTimeImmutable { return $this->billDate; }
    public function getDueDate(): ?\DateTimeImmutable { return $this->dueDate; }
}