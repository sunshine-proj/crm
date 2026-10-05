<?php

namespace App\Entity;

use App\Repository\StockMovementRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: StockMovementRepository::class)]
#[ORM\Table(name: 'stock_movement')]
#[ORM\Index(columns: ['warehouse_id', 'product_id', 'moved_at'], name: 'idx_stock_movement_ledger')]
#[ORM\Index(columns: ['source_type', 'source_id'], name: 'idx_stock_movement_source')]
#[ORM\UniqueConstraint(name: 'uniq_stock_movement_source_product_type', columns: ['source_type', 'source_id', 'product_id', 'movement_type'])]
class StockMovement
{
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

    #[ORM\Column(length: 32)]
    private string $movementType;

    #[ORM\Column(type: Types::BIGINT)]
    private int $quantityDelta;

    #[ORM\Column(length: 32)]
    private string $sourceType;

    #[ORM\Column(nullable: true)]
    private ?int $sourceId;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $movedAt;

    #[ORM\Column(length: 255)]
    private string $memo;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Product $product, Warehouse $warehouse, string $movementType, int $quantityDelta, string $sourceType, ?int $sourceId, \DateTimeImmutable $movedAt, string $memo)
    {
        if ($quantityDelta === 0) {
            throw new \InvalidArgumentException('Stock movement quantity cannot be zero.');
        }
        $this->product = $product;
        $this->warehouse = $warehouse;
        $this->movementType = $movementType;
        $this->quantityDelta = $quantityDelta;
        $this->sourceType = $sourceType;
        $this->sourceId = $sourceId;
        $this->movedAt = $movedAt;
        $this->memo = $memo;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getProduct(): Product { return $this->product; }
    public function getWarehouse(): Warehouse { return $this->warehouse; }
    public function getMovementType(): string { return $this->movementType; }
    public function getQuantityDelta(): int { return $this->quantityDelta; }
    public function getSourceType(): string { return $this->sourceType; }
    public function getSourceId(): ?int { return $this->sourceId; }
    public function getMovedAt(): \DateTimeImmutable { return $this->movedAt; }
    public function getMemo(): string { return $this->memo; }
}