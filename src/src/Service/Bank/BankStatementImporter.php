<?php

namespace App\Service\Bank;

use App\Entity\BankAccount;
use App\Entity\BankStatementImport;
use App\Entity\BankStatementLine;
use App\Repository\BankStatementImportRepository;
use App\Repository\BankStatementLineRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Imports a statement file into stored, de-duplicated statement lines. Re-importing the same file is refused, and
 * overlapping statements never create a transaction twice (identical lines on one day are kept apart by their order).
 */
final class BankStatementImporter
{
    public const MAX_BYTES = 5_000_000;
    public const MAX_LINES = 5000;

    public function __construct(
        private readonly Camt053Parser $camt,
        private readonly CsvStatementParser $csv,
        private readonly BankStatementImportRepository $importRepository,
        private readonly BankStatementLineRepository $lineRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array{import: BankStatementImport, imported: int, skipped: int}
     * @throws \InvalidArgumentException for unreadable files; \DomainException for files that conflict with the account
     */
    public function import(BankAccount $account, string $filename, string $format, string $content, string $actor): array
    {
        if (strlen($content) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('The statement file is larger than 5 MB.');
        }
        if (!$account->isActive()) {
            throw new \DomainException('This bank account is inactive.');
        }
        $hash = hash('sha256', $content);
        if ($this->importRepository->findOneBy(['bankAccount' => $account, 'fileHash' => $hash]) !== null) {
            throw new \DomainException('This exact file has already been imported for this bank account.');
        }
        $parsed = match ($format) {
            'camt053' => $this->camt->parse($content),
            'csv' => $this->csv->parse($content, $account->getCurrency()),
            default => throw new \InvalidArgumentException('Unsupported statement format; use camt053 or csv.'),
        };
        if ($parsed->lines === []) {
            throw new \InvalidArgumentException('The statement contains no booked transactions.');
        }
        if (count($parsed->lines) > self::MAX_LINES) {
            throw new \InvalidArgumentException('The statement has more than '.self::MAX_LINES.' transactions; split it.');
        }
        if ($parsed->accountIban !== null && $account->getIban() !== null && strtoupper($parsed->accountIban) !== $account->getIban()) {
            throw new \DomainException('The statement belongs to a different account ('.$parsed->accountIban.').');
        }
        $total = 0;
        foreach ($parsed->lines as $line) {
            if ($line['currency'] !== $account->getCurrency()) {
                throw new \DomainException(sprintf('The statement contains %s transactions but the account is in %s.', $line['currency'], $account->getCurrency()));
            }
            $total += $line['amount_minor'];
        }
        if ($parsed->openingBalanceMinor !== null && $parsed->closingBalanceMinor !== null && $parsed->openingBalanceMinor + $total !== $parsed->closingBalanceMinor) {
            throw new \DomainException('The statement is inconsistent: the opening balance plus the transactions does not equal the closing balance.');
        }

        $dates = array_map(static fn (array $line): \DateTimeImmutable => $line['date'], $parsed->lines);
        $import = new BankStatementImport($account, $filename, $format, $hash, $parsed->openingBalanceMinor, $parsed->closingBalanceMinor, min($dates), max($dates), $actor);

        $keys = [];
        $seen = [];
        foreach ($parsed->lines as $index => $line) {
            $base = hash('sha256', implode('|', [$line['date']->format('Y-m-d'), $line['amount_minor'], $line['currency'], $line['external_id'] ?? ($line['reference'].'|'.($line['counterparty'] ?? ''))]));
            $seen[$base] = ($seen[$base] ?? 0) + 1;
            $keys[$index] = hash('sha256', $base.'#'.$seen[$base]);
        }
        $existing = $this->lineRepository->existingKeys($account, array_values($keys));

        $this->entityManager->persist($import);
        $imported = 0;
        $skipped = 0;
        foreach ($parsed->lines as $index => $line) {
            if (isset($existing[$keys[$index]])) {
                ++$skipped;
                continue;
            }
            $this->entityManager->persist(new BankStatementLine($import, $line['date'], $line['amount_minor'], $line['currency'], $line['reference'], $line['counterparty'], $line['external_id'], $keys[$index]));
            ++$imported;
        }
        $import->recordCounts($imported, $skipped);
        $this->entityManager->flush();

        return ['import' => $import, 'imported' => $imported, 'skipped' => $skipped];
    }
}
