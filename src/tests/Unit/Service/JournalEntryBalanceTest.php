<?php

namespace App\Tests\Unit\Service;

use App\Service\JournalEntryBalance;
use PHPUnit\Framework\TestCase;

final class JournalEntryBalanceTest extends TestCase
{
    public function test_accepts_balanced_integer_minor_unit_lines(): void
    {
        $balance = new JournalEntryBalance();

        self::assertSame(
            ['debit_minor' => 12550, 'credit_minor' => 12550],
            $balance->totals([
                ['debit_minor' => 12550, 'credit_minor' => 0],
                ['debit_minor' => 0, 'credit_minor' => 12550],
            ]),
        );
    }

    public function test_rejects_unbalanced_or_invalid_entries(): void
    {
        $balance = new JournalEntryBalance();

        self::assertNull($balance->totals([['debit_minor' => 100, 'credit_minor' => 0]]));
        self::assertNull($balance->totals([
            ['debit_minor' => 100, 'credit_minor' => 0],
            ['debit_minor' => 0, 'credit_minor' => 99],
        ]));
        self::assertNull($balance->totals([
            ['debit_minor' => 100, 'credit_minor' => 1],
            ['debit_minor' => 0, 'credit_minor' => 99],
        ]));
        self::assertNull($balance->totals([
            ['debit_minor' => PHP_INT_MAX, 'credit_minor' => 0],
            ['debit_minor' => 1, 'credit_minor' => 0],
            ['debit_minor' => 0, 'credit_minor' => PHP_INT_MAX],
            ['debit_minor' => 0, 'credit_minor' => 1],
        ]));
    }
}