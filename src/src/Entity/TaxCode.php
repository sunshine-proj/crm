<?php

namespace App\Entity;

use App\Repository\TaxCodeRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TaxCodeRepository::class)]
#[ORM\Table(name: 'tax_code')]
#[ORM\UniqueConstraint(name: 'UNIQ_TAX_CODE_CODE', columns: ['code'])]
class TaxCode
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32)]
    private string $code;

    #[ORM\Column(length: 160)]
    private string $name;

    #[ORM\Column]
    private int $rateBasisPoints;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    public function __construct(string $code, string $name, int $rateBasisPoints, bool $active = true)
    {
        $this->code = $code;
        $this->name = $name;
        $this->rateBasisPoints = $rateBasisPoints;
        $this->active = $active;
    }

    public function getId(): ?int { return $this->id; }
    public function getCode(): string { return $this->code; }
    public function getName(): string { return $this->name; }
    public function getRateBasisPoints(): int { return $this->rateBasisPoints; }
    public function isActive(): bool { return $this->active; }
    public function update(string $code, string $name, int $rateBasisPoints, bool $active): static
    {
        $this->code = $code;
        $this->name = $name;
        $this->rateBasisPoints = $rateBasisPoints;
        $this->active = $active;
        return $this;
    }
}