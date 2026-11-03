<?php

namespace App\Entity;

use App\Repository\CreditNoteRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Immutable correction of an issued invoice. Issued invoices are never edited: a credit note reverses part or all of
 * their revenue and tax and reduces the receivable.
 */
#[ORM\Entity(repositoryClass: CreditNoteRepository::class)]
#[ORM\Table(name: 'credit_note')]
#[ORM\UniqueConstraint(name: 'uniq_credit_note_number', columns: ['credit_note_number'])]
#[ORM\Index(columns: ['invoice_id'], name: 'idx_credit_note_invoice')]
class CreditNote
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Invoice::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Invoice $invoice;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $creditNoteNumber = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $issueDate;

    #[ORM\Column(length: 500)]
    private string $reason;

    #[ORM\Column(length: 3)]
    private string $currency;

    /** @var list<array{description: string, amount: string, tax_code: string|null, tax_rate_bp: int}> amount is net */
    #[ORM\Column(type: Types::JSON)]
    private array $items;

    #[ORM\Column(type: Types::BIGINT)]
    private int $netMinor;

    #[ORM\Column(type: Types::BIGINT)]
    private int $taxMinor;

    #[ORM\Column(type: Types::BIGINT)]
    private int $totalMinor;

    /** @var list<array{code: string|null, rate_bp: int, net_minor: int, tax_minor: int}> */
    #[ORM\Column(type: Types::JSON)]
    private array $taxBreakdown;

    #[ORM\Column(length: 180)]
    private string $issuedBy;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * @param list<array{description: string, amount: string, tax_code: string|null, tax_rate_bp: int}> $items
     * @param list<array{code: string|null, rate_bp: int, net_minor: int, tax_minor: int}> $taxBreakdown
     */
    public function __construct(Invoice $invoice, \DateTimeImmutable $issueDate, string $reason, array $items, int $netMinor, int $taxMinor, array $taxBreakdown, string $issuedBy)
    {
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('A credit note needs a reason.');
        }
        if ($netMinor < 1 || $taxMinor < 0) {
            throw new \InvalidArgumentException('A credit note must credit a positive amount.');
        }
        $this->invoice = $invoice;
        $this->issueDate = $issueDate;
        $this->reason = trim($reason);
        $this->currency = $invoice->getCurrency();
        $this->items = $items;
        $this->netMinor = $netMinor;
        $this->taxMinor = $taxMinor;
        $this->totalMinor = $netMinor + $taxMinor;
        $this->taxBreakdown = $taxBreakdown;
        $this->issuedBy = $issuedBy;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function assignNumber(string $number): static
    {
        if ($this->creditNoteNumber !== null) {
            throw new \DomainException('The credit note already has a number.');
        }
        $this->creditNoteNumber = $number;

        return $this;
    }

    public function getId(): ?int { return $this->id; }
    public function getInvoice(): Invoice { return $this->invoice; }
    public function getCreditNoteNumber(): ?string { return $this->creditNoteNumber; }
    public function getIssueDate(): \DateTimeImmutable { return $this->issueDate; }
    public function getReason(): string { return $this->reason; }
    public function getCurrency(): string { return $this->currency; }
    public function getItems(): array { return $this->items; }
    public function getNetMinor(): int { return $this->netMinor; }
    public function getTaxMinor(): int { return $this->taxMinor; }
    public function getTotalMinor(): int { return $this->totalMinor; }
    public function getTaxBreakdown(): array { return $this->taxBreakdown; }
    public function getIssuedBy(): string { return $this->issuedBy; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
