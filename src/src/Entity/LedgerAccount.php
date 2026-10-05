<?php

namespace App\Entity;

use App\Repository\LedgerAccountRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: LedgerAccountRepository::class)]
#[ORM\Table(name: 'ledger_account')]
#[ORM\UniqueConstraint(name: 'UNIQ_LEDGER_ACCOUNT_CODE', columns: ['code'])]
class LedgerAccount
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32)]
    private string $code;

    #[ORM\Column(length: 160)]
    private string $name;

    #[ORM\Column(length: 16)]
    private string $type;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $code, string $name, string $type, bool $active = true)
    {
        $this->code = $code;
        $this->name = $name;
        $this->type = $type;
        $this->active = $active;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int { return $this->id; }
    public function getCode(): string { return $this->code; }
    public function getName(): string { return $this->name; }
    public function getType(): string { return $this->type; }
    public function isActive(): bool { return $this->active; }

    public function update(string $code, string $name, string $type, bool $active): static
    {
        $this->code = $code;
        $this->name = $name;
        $this->type = $type;
        $this->active = $active;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }
}