<?php

namespace App\Entity;

use App\Repository\WarehouseRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WarehouseRepository::class)]
#[ORM\Table(name: 'warehouse')]
#[ORM\UniqueConstraint(name: 'UNIQ_WAREHOUSE_CODE', columns: ['code'])]
class Warehouse
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32)]
    private string $code;

    #[ORM\Column(length: 160)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $address = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $code, string $name, ?string $address)
    {
        $this->code = $code;
        $this->name = $name;
        $this->address = $address;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int { return $this->id; }
    public function getCode(): string { return $this->code; }
    public function getName(): string { return $this->name; }
    public function getAddress(): ?string { return $this->address; }
    public function isActive(): bool { return $this->active; }

    public function update(string $code, string $name, ?string $address, bool $active): static
    {
        $this->code = $code;
        $this->name = $name;
        $this->address = $address;
        $this->active = $active;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }
}