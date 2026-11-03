<?php

namespace App\Entity;

use App\Repository\GoodsReceiptRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: GoodsReceiptRepository::class)]
#[ORM\Table(name: 'goods_receipt')]
#[ORM\Index(columns: ['purchase_order_id'], name: 'idx_goods_receipt_order')]
#[ORM\Index(columns: ['warehouse_id'], name: 'idx_goods_receipt_warehouse')]
class GoodsReceipt
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PurchaseOrder::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private PurchaseOrder $purchaseOrder;

    #[ORM\ManyToOne(targetEntity: Warehouse::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Warehouse $warehouse;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $reference;

    /** @var list<array{product_id: int, description: string, quantity: int}> */
    #[ORM\Column(type: Types::JSON)]
    private array $items;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $receivedAt;

    /** @var list<array{description: string, amount_minor: int}> freight, duty and similar costs capitalised into inventory */
    #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
    private array $landedCosts = [];

    #[ORM\Column(type: Types::BIGINT, options: ['default' => 0])]
    private int $landedTotalMinor = 0;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** @param list<array{product_id: int, description: string, quantity: int}> $items */
    public function __construct(PurchaseOrder $purchaseOrder, Warehouse $warehouse, array $items, \DateTimeImmutable $receivedAt, ?string $reference)
    {
        $this->purchaseOrder = $purchaseOrder;
        $this->warehouse = $warehouse;
        $this->items = $items;
        $this->receivedAt = $receivedAt;
        $this->reference = $reference;
        $this->createdAt = new \DateTimeImmutable();
    }

    /** @param list<array{description: string, amount_minor: int}> $landedCosts */
    public function withLandedCosts(array $landedCosts): static
    {
        $total = 0;
        foreach ($landedCosts as $cost) {
            if ($cost['amount_minor'] < 1 || $cost['amount_minor'] > PHP_INT_MAX - $total) {
                throw new \InvalidArgumentException('Landed cost amounts must be positive and within range.');
            }
            $total += $cost['amount_minor'];
        }
        $this->landedCosts = $landedCosts;
        $this->landedTotalMinor = $total;

        return $this;
    }

    /** @return list<array{description: string, amount_minor: int}> */
    public function getLandedCosts(): array { return $this->landedCosts; }
    public function getLandedTotalMinor(): int { return $this->landedTotalMinor; }
    public function getId(): ?int { return $this->id; }
    public function getPurchaseOrder(): PurchaseOrder { return $this->purchaseOrder; }
    public function getWarehouse(): Warehouse { return $this->warehouse; }
    public function getItems(): array { return $this->items; }
    public function getReceivedAt(): \DateTimeImmutable { return $this->receivedAt; }
    public function getReference(): ?string { return $this->reference; }
}