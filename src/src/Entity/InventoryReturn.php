<?php

namespace App\Entity;

use App\Repository\InventoryReturnRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InventoryReturnRepository::class)]
#[ORM\Table(name: 'inventory_return')]
#[ORM\Index(columns: ['return_type', 'source_type', 'source_id'], name: 'idx_inventory_return_source')]
class InventoryReturn
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 16)]
    private string $returnType;

    #[ORM\ManyToOne(targetEntity: Warehouse::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Warehouse $warehouse;

    #[ORM\Column(length: 24)]
    private string $sourceType;

    #[ORM\Column]
    private int $sourceId;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $reference;

    /** @var list<array{product_id: int, description: string, quantity: int}> */
    #[ORM\Column(type: Types::JSON)]
    private array $items;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $returnedAt;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** @param list<array{product_id: int, description: string, quantity: int}> $items */
    public function __construct(string $returnType, Warehouse $warehouse, string $sourceType, int $sourceId, array $items, \DateTimeImmutable $returnedAt, ?string $reference)
    {
        $this->returnType = $returnType;
        $this->warehouse = $warehouse;
        $this->sourceType = $sourceType;
        $this->sourceId = $sourceId;
        $this->items = $items;
        $this->returnedAt = $returnedAt;
        $this->reference = $reference;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getReturnType(): string { return $this->returnType; }
    public function getWarehouse(): Warehouse { return $this->warehouse; }
    public function getSourceType(): string { return $this->sourceType; }
    public function getSourceId(): int { return $this->sourceId; }
    public function getItems(): array { return $this->items; }
    public function getReturnedAt(): \DateTimeImmutable { return $this->returnedAt; }
    public function getReference(): ?string { return $this->reference; }
}