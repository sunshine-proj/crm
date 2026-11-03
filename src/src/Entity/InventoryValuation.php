<?php

namespace App\Entity;

use App\Repository\InventoryValuationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Append-only value of one stock event under the weighted-average cost method. Quantity and value carry the same
 * sign: receipts add, issues remove value at the pool's average cost. The journal entry for each row uses source type
 * "inventory_<kind>" and this row's id.
 */
#[ORM\Entity(repositoryClass: InventoryValuationRepository::class)]
#[ORM\Table(name: 'inventory_valuation')]
#[ORM\Index(columns: ['product_id', 'warehouse_id'], name: 'idx_inventory_valuation_pool')]
#[ORM\Index(columns: ['source_type', 'source_id'], name: 'idx_inventory_valuation_source')]
#[ORM\UniqueConstraint(name: 'uniq_inventory_valuation_event', columns: ['kind', 'source_id', 'product_id'])]
class InventoryValuation
{
    public const RECEIPT = 'receipt';
    public const DISPATCH = 'dispatch';
    public const CUSTOMER_RETURN = 'customer_return';
    public const SUPPLIER_RETURN = 'supplier_return';
    public const ADJUSTMENT = 'adjustment';
    public const OPENING = 'opening';

    public const KINDS = [self::RECEIPT, self::DISPATCH, self::CUSTOMER_RETURN, self::SUPPLIER_RETURN, self::ADJUSTMENT, self::OPENING];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Product $product;

    #[ORM\ManyToOne(targetEntity: Warehouse::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Warehouse $warehouse;

    #[ORM\Column(length: 20)]
    private string $kind;

    /** Id of the business record: goods receipt, sales shipment, inventory return, stock movement (adjustment) or the product pool (opening). */
    #[ORM\Column(length: 32)]
    private string $sourceType;

    #[ORM\Column]
    private int $sourceId;

    #[ORM\Column(type: Types::BIGINT)]
    private int $quantityDelta;

    #[ORM\Column(type: Types::BIGINT)]
    private int $valueMinor;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $movedAt;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Product $product, Warehouse $warehouse, string $kind, string $sourceType, int $sourceId, int $quantityDelta, int $valueMinor, string $currency, \DateTimeImmutable $movedAt)
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('Unsupported valuation kind.');
        }
        if ($quantityDelta === 0 || ($quantityDelta > 0) !== ($valueMinor >= 0)) {
            throw new \InvalidArgumentException('Valuation quantity must be non-zero and share the sign of its value.');
        }
        $this->product = $product;
        $this->warehouse = $warehouse;
        $this->kind = $kind;
        $this->sourceType = $sourceType;
        $this->sourceId = $sourceId;
        $this->quantityDelta = $quantityDelta;
        $this->valueMinor = $valueMinor;
        $this->currency = $currency;
        $this->movedAt = $movedAt;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getProduct(): Product { return $this->product; }
    public function getWarehouse(): Warehouse { return $this->warehouse; }
    public function getKind(): string { return $this->kind; }
    public function getSourceType(): string { return $this->sourceType; }
    public function getSourceId(): int { return $this->sourceId; }
    public function getQuantityDelta(): int { return $this->quantityDelta; }
    public function getValueMinor(): int { return $this->valueMinor; }
    public function getCurrency(): string { return $this->currency; }
    public function getMovedAt(): \DateTimeImmutable { return $this->movedAt; }
}
