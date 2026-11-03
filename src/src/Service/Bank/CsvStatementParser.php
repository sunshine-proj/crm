<?php

namespace App\Service\Bank;

/**
 * Reads a simple CSV export. A header row is required; columns are matched by name (case-insensitive):
 * date (or booking_date), amount, currency (optional, defaults to the account currency), reference (or description,
 * details), counterparty (or name, payee, payer) and id (or transaction_id). Amounts are signed: positive money in.
 */
final class CsvStatementParser
{
    private const ALIASES = [
        'date' => ['date', 'booking_date', 'booking date', 'booked', 'value_date'],
        'amount' => ['amount', 'amount_eur', 'sum'],
        'currency' => ['currency', 'ccy'],
        'reference' => ['reference', 'description', 'details', 'remittance', 'payment details'],
        'counterparty' => ['counterparty', 'name', 'payee', 'payer', 'beneficiary'],
        'id' => ['id', 'transaction_id', 'transaction id', 'bank_reference'],
    ];

    public function __construct(private readonly AmountParser $amounts)
    {
    }

    public function parse(string $csv, string $defaultCurrency): ParsedStatement
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
        $rows = array_map(static fn (string $row): string => $row, preg_split('/\r\n|\n|\r/', trim($csv)) ?: []);
        if (count($rows) < 2) {
            throw new \InvalidArgumentException('The CSV needs a header row and at least one transaction.');
        }
        $delimiter = $this->delimiter($rows[0]);
        $header = array_map(static fn (?string $cell): string => strtolower(trim((string) $cell)), str_getcsv($rows[0], $delimiter, '"', '\\'));
        $columns = [];
        foreach (self::ALIASES as $field => $aliases) {
            foreach ($aliases as $alias) {
                if (($index = array_search($alias, $header, true)) !== false) {
                    $columns[$field] = $index;
                    break;
                }
            }
        }
        if (!isset($columns['date'], $columns['amount'])) {
            throw new \InvalidArgumentException('The CSV header must contain a date column and an amount column.');
        }

        $lines = [];
        foreach (array_slice($rows, 1) as $number => $row) {
            if (trim($row) === '') {
                continue;
            }
            $cells = str_getcsv($row, $delimiter, '"', '\\');
            $cell = static fn (string $field): ?string => isset($columns[$field], $cells[$columns[$field]]) && trim((string) $cells[$columns[$field]]) !== '' ? trim((string) $cells[$columns[$field]]) : null;
            $minor = $this->amounts->parseMinor((string) $cell('amount'));
            if ($minor === null) {
                throw new \InvalidArgumentException(sprintf('Row %d has an invalid amount.', $number + 2));
            }
            if ($minor === 0) {
                continue;
            }
            $lines[] = [
                'date' => $this->date((string) $cell('date'), $number + 2),
                'amount_minor' => $minor,
                'currency' => strtoupper($cell('currency') ?? $defaultCurrency),
                'reference' => $cell('reference') ?? '',
                'counterparty' => $cell('counterparty'),
                'external_id' => $cell('id'),
            ];
        }

        return new ParsedStatement($lines);
    }

    private function delimiter(string $header): string
    {
        return substr_count($header, ';') > substr_count($header, ',') ? ';' : (substr_count($header, "\t") > substr_count($header, ',') ? "\t" : ',');
    }

    private function date(string $value, int $row): \DateTimeImmutable
    {
        foreach (['!Y-m-d', '!d.m.Y', '!d/m/Y', '!Y/m/d'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value);
            if ($date !== false && $date->format(ltrim($format, '!')) === $value) {
                return $date;
            }
        }
        throw new \InvalidArgumentException(sprintf('Row %d has an unrecognised date (use YYYY-MM-DD, DD.MM.YYYY or DD/MM/YYYY).', $row));
    }
}
