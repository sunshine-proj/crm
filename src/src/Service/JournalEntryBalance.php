<?php

namespace App\Service;

final class JournalEntryBalance
{
    /**
     * @param list<array{debit_minor: int, credit_minor: int}> $lines
     * @return array{debit_minor: int, credit_minor: int}|null
     */
    public function totals(array $lines): ?array
    {
        if (count($lines) < 2) {
            return null;
        }

        $debitTotal = 0;
        $creditTotal = 0;
        foreach ($lines as $line) {
            $debit = $line['debit_minor'] ?? -1;
            $credit = $line['credit_minor'] ?? -1;
            if (!is_int($debit) || !is_int($credit) || $debit < 0 || $credit < 0 || (($debit > 0) === ($credit > 0))) {
                return null;
            }
            if ($debit > PHP_INT_MAX - $debitTotal || $credit > PHP_INT_MAX - $creditTotal) {
                return null;
            }

            $debitTotal += $debit;
            $creditTotal += $credit;
        }

        if ($debitTotal < 1 || $debitTotal !== $creditTotal) {
            return null;
        }

        return ['debit_minor' => $debitTotal, 'credit_minor' => $creditTotal];
    }
}