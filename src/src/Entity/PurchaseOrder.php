<?php

namespace App\Entity;

use App\Repository\PurchaseOrderRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PurchaseOrderRepository::class)]
#[ORM\Table(name: 'purchase_order')]
#[ORM\Index(columns: ['supplier_id', 'status'], name: 'idx_purchase_order_supplier_status')]
#[ORM\UniqueConstraint(name: 'UNIQ_PURCHASE_ORDER_NUMBER', columns: ['order_number'])]
class PurchaseOrder
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $orderNumber = null;

    #[ORM\ManyToOne(targetEntity: Supplier::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Supplier $supplier;

    #[ORM\Column(length: 3)]
    private string $currency;

    /** @var list<array{product_id: int, sku: string, description: string, quantity: int, unit_cost_minor: int, line_total_minor: int}> */
    #[ORM\Column(type: Types::JSON)]
    private array $items;

    #[ORM\Column(type: Types::BIGINT)]
    private int $totalMinor;

    #[ORM\Column(length: 24)]
    private string $status = 'open';

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $orderedAt;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /** @param list<array{product_id: int, sku: string, description: string, quantity: int, unit_cost_minor: int, line_total_minor: int}> $items */
    public function __construct(Supplier $supplier, string $currency, array $items, int $totalMinor, \DateTimeImmutable $orderedAt)
    {
        $this->supplier = $supplier;
        $this->currency = $currency;
        $this->items = $items;
        $this->totalMinor = $totalMinor;
        $this->orderedAt = $orderedAt;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int { return $this->id; }
    public function getOrderNumber(): ?string { return $this->orderNumber; }
    public function getSupplier(): Supplier { return $this->supplier; }
    public function getCurrency(): string { return $this->currency; }
    public function getItems(): array { return $this->items; }
    public function getTotalMinor(): int { return $this->totalMinor; }
    public function getStatus(): string { return $this->status; }
    public function getOrderedAt(): \DateTimeImmutable { return $this->orderedAt; }
    public function setOrderNumber(string $orderNumber): static { $this->orderNumber = $orderNumber; return $this; }
    public function setStatus(string $status): static { $this->status = $status; $this->updatedAt = new \DateTimeImmutable(); return $this; }
}