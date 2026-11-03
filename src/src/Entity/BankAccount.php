<?php

namespace App\Entity;

use App\Repository\BankAccountRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** A bank account whose statements are imported and reconciled against recorded receipts and payments. */
#[ORM\Entity(repositoryClass: BankAccountRepository::class)]
#[ORM\Table(name: 'bank_account')]
#[ORM\UniqueConstraint(name: 'uniq_bank_account_name', columns: ['name'])]
class BankAccount
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(length: 34, nullable: true)]
    private ?string $iban;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\ManyToOne(targetEntity: LedgerAccount::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?LedgerAccount $ledgerAccount;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $name, ?string $iban, string $currency, ?LedgerAccount $ledgerAccount)
    {
        if (trim($name) === '') {
            throw new \InvalidArgumentException('A bank account needs a name.');
        }
        $this->name = trim($name);
        $this->iban = $iban !== null && trim($iban) !== '' ? strtoupper(preg_replace('/\s+/', '', $iban) ?? '') : null;
        $this->currency = $currency;
        $this->ledgerAccount = $ledgerAccount;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function getIban(): ?string { return $this->iban; }
    public function getCurrency(): string { return $this->currency; }
    public function getLedgerAccount(): ?LedgerAccount { return $this->ledgerAccount; }
    public function isActive(): bool { return $this->active; }
}
