<?php

namespace App\Entity;

use App\Repository\BankStatementLineRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** One booked transaction from a bank statement. Amount is signed: positive money in, negative money out. */
#[ORM\Entity(repositoryClass: BankStatementLineRepository::class)]
#[ORM\Table(name: 'bank_statement_line')]
#[ORM\UniqueConstraint(name: 'uniq_bank_line_dedupe', columns: ['bank_account_id', 'dedupe_key'])]
#[ORM\Index(columns: ['bank_account_id', 'booking_date'], name: 'idx_bank_line_account_date')]
#[ORM\Index(columns: ['match_type', 'match_id'], name: 'idx_bank_line_match')]
class BankStatementLine
{
    public const CUSTOMER_PAYMENT = 'customer_payment';
    public const VENDOR_PAYMENT = 'vendor_payment';
    public const IGNORED = 'ignored';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: BankStatementImport::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private BankStatementImport $import;

    #[ORM\ManyToOne(targetEntity: BankAccount::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private BankAccount $bankAccount;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $bookingDate;

    #[ORM\Column(type: Types::BIGINT)]
    private int $amountMinor;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(length: 500)]
    private string $reference;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $counterparty;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $externalId;

    #[ORM\Column(length: 64)]
    private string $dedupeKey;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $matchType = null;

    #[ORM\Column(nullable: true)]
    private ?int $matchId = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $matchedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $matchedAt = null;

    public function __construct(BankStatementImport $import, \DateTimeImmutable $bookingDate, int $amountMinor, string $currency, string $reference, ?string $counterparty, ?string $externalId, string $dedupeKey)
    {
        if ($amountMinor === 0) {
            throw new \InvalidArgumentException('A statement line cannot be zero.');
        }
        $this->import = $import;
        $this->bankAccount = $import->getBankAccount();
        $this->bookingDate = $bookingDate;
        $this->amountMinor = $amountMinor;
        $this->currency = $currency;
        $this->reference = mb_substr($reference, 0, 500);
        $this->counterparty = $counterparty !== null ? mb_substr($counterparty, 0, 255) : null;
        $this->externalId = $externalId !== null ? mb_substr($externalId, 0, 128) : null;
        $this->dedupeKey = $dedupeKey;
    }

    public function matchTo(string $type, int $id, string $actor): static
    {
        if (!in_array($type, [self::CUSTOMER_PAYMENT, self::VENDOR_PAYMENT], true)) {
            throw new \InvalidArgumentException('Unsupported match type.');
        }
        if ($this->matchType !== null) {
            throw new \DomainException('This statement line is already matched or ignored.');
        }
        if (($type === self::CUSTOMER_PAYMENT) !== ($this->amountMinor > 0)) {
            throw new \DomainException('Money in matches a customer receipt; money out matches a vendor payment.');
        }
        $this->matchType = $type;
        $this->matchId = $id;
        $this->matchedBy = $actor;
        $this->matchedAt = new \DateTimeImmutable();

        return $this;
    }

    public function ignore(string $reason, string $actor): static
    {
        if ($this->matchType !== null) {
            throw new \DomainException('This statement line is already matched or ignored.');
        }
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('A reason is required to ignore a statement line.');
        }
        $this->matchType = self::IGNORED;
        $this->note = trim($reason);
        $this->matchedBy = $actor;
        $this->matchedAt = new \DateTimeImmutable();

        return $this;
    }

    public function unmatch(): static
    {
        $this->matchType = null;
        $this->matchId = null;
        $this->note = null;
        $this->matchedBy = null;
        $this->matchedAt = null;

        return $this;
    }

    public function getId(): ?int { return $this->id; }
    public function getImport(): BankStatementImport { return $this->import; }
    public function getBankAccount(): BankAccount { return $this->bankAccount; }
    public function getBookingDate(): \DateTimeImmutable { return $this->bookingDate; }
    public function getAmountMinor(): int { return $this->amountMinor; }
    public function getCurrency(): string { return $this->currency; }
    public function getReference(): string { return $this->reference; }
    public function getCounterparty(): ?string { return $this->counterparty; }
    public function getExternalId(): ?string { return $this->externalId; }
    public function getMatchType(): ?string { return $this->matchType; }
    public function getMatchId(): ?int { return $this->matchId; }
    public function getNote(): ?string { return $this->note; }
    public function getMatchedBy(): ?string { return $this->matchedBy; }
    public function getMatchedAt(): ?\DateTimeImmutable { return $this->matchedAt; }
    public function isOpen(): bool { return $this->matchType === null; }
}
