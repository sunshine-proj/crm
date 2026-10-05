<?php

namespace App\Entity;

use App\Repository\ProductRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProductRepository::class)]
#[ORM\Table(name: 'product')]
#[ORM\UniqueConstraint(name: 'UNIQ_PRODUCT_SKU', columns: ['sku'])]
class Product
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $sku;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(type: Types::BIGINT)]
    private int $unitPriceMinor;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $sku, string $name, string $currency, int $unitPriceMinor)
    {
        $this->sku = $sku;
        $this->name = $name;
        $this->currency = $currency;
        $this->unitPriceMinor = $unitPriceMinor;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int { return $this->id; }
    public function getSku(): string { return $this->sku; }
    public function getName(): string { return $this->name; }
    public function getDescription(): ?string { return $this->description; }
    public function getCurrency(): string { return $this->currency; }
    public function getUnitPriceMinor(): int { return $this->unitPriceMinor; }
    public function isActive(): bool { return $this->active; }

    public function update(string $sku, string $name, ?string $description, string $currency, int $unitPriceMinor, bool $active): static
    {
        $this->sku = $sku;
        $this->name = $name;
        $this->description = $description;
        $this->currency = $currency;
        $this->unitPriceMinor = $unitPriceMinor;
        $this->active = $active;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}