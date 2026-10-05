<?php

namespace App\Entity;

use App\Repository\ShipmentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ShipmentRepository::class)]
#[ORM\Table(name: 'shipment')]
class Shipment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SalesDocument::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private SalesDocument $salesOrder;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $reference = null;

    /** @var list<array{product_id: int, description: string, quantity: int}> */
    #[ORM\Column(type: Types::JSON)]
    private array $items = [];

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $shippedAt;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** @param list<array{product_id: int, description: string, quantity: int}> $items */
    public function __construct(SalesDocument $salesOrder, array $items, \DateTimeImmutable $shippedAt, ?string $reference)
    {
        $this->salesOrder = $salesOrder;
        $this->items = $items;
        $this->shippedAt = $shippedAt;
        $this->reference = $reference;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getSalesOrder(): SalesDocument { return $this->salesOrder; }
    public function getItems(): array { return $this->items; }
    public function getShippedAt(): \DateTimeImmutable { return $this->shippedAt; }
    public function getReference(): ?string { return $this->reference; }
}