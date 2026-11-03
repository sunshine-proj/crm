<?php

namespace App\Entity;

use App\Repository\BankStatementImportRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BankStatementImportRepository::class)]
#[ORM\Table(name: 'bank_statement_import')]
#[ORM\UniqueConstraint(name: 'uniq_bank_import_file', columns: ['bank_account_id', 'file_hash'])]
class BankStatementImport
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: BankAccount::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private BankAccount $bankAccount;

    #[ORM\Column(length: 255)]
    private string $filename;

    #[ORM\Column(length: 16)]
    private string $format;

    /** SHA-256 of the file content: importing the same file twice is refused. */
    #[ORM\Column(length: 64)]
    private string $fileHash;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $openingBalanceMinor;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $closingBalanceMinor;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $periodFrom;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $periodTo;

    #[ORM\Column]
    private int $lineCount = 0;

    #[ORM\Column]
    private int $skippedCount = 0;

    #[ORM\Column(length: 180)]
    private string $importedBy;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $importedAt;

    public function __construct(BankAccount $bankAccount, string $filename, string $format, string $fileHash, ?int $openingBalanceMinor, ?int $closingBalanceMinor, ?\DateTimeImmutable $periodFrom, ?\DateTimeImmutable $periodTo, string $importedBy)
    {
        $this->bankAccount = $bankAccount;
        $this->filename = mb_substr($filename, 0, 255);
        $this->format = $format;
        $this->fileHash = $fileHash;
        $this->openingBalanceMinor = $openingBalanceMinor;
        $this->closingBalanceMinor = $closingBalanceMinor;
        $this->periodFrom = $periodFrom;
        $this->periodTo = $periodTo;
        $this->importedBy = $importedBy;
        $this->importedAt = new \DateTimeImmutable();
    }

    public function recordCounts(int $imported, int $skipped): static
    {
        $this->lineCount = $imported;
        $this->skippedCount = $skipped;

        return $this;
    }

    public function getId(): ?int { return $this->id; }
    public function getBankAccount(): BankAccount { return $this->bankAccount; }
    public function getFilename(): string { return $this->filename; }
    public function getFormat(): string { return $this->format; }
    public function getOpeningBalanceMinor(): ?int { return $this->openingBalanceMinor; }
    public function getClosingBalanceMinor(): ?int { return $this->closingBalanceMinor; }
    public function getPeriodFrom(): ?\DateTimeImmutable { return $this->periodFrom; }
    public function getPeriodTo(): ?\DateTimeImmutable { return $this->periodTo; }
    public function getLineCount(): int { return $this->lineCount; }
    public function getSkippedCount(): int { return $this->skippedCount; }
    public function getImportedBy(): string { return $this->importedBy; }
    public function getImportedAt(): \DateTimeImmutable { return $this->importedAt; }
}
