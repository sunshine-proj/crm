<?php

namespace App\Service;

final class FinanceCatalog
{
    /** @return list<array{code: string, name: string, minor_unit: int}> */
    public function currencies(): array
    {
        return [
            ['code' => 'EUR', 'name' => 'Euro', 'minor_unit' => 2],
            ['code' => 'GBP', 'name' => 'Pound sterling', 'minor_unit' => 2],
            ['code' => 'USD', 'name' => 'US dollar', 'minor_unit' => 2],
        ];
    }

    public function supportsCurrency(string $code): bool
    {
        foreach ($this->currencies() as $currency) {
            if ($currency['code'] === $code) {
                return true;
            }
        }
        return false;
    }

    public function parseRateBasisPoints(string $rate): ?int
    {
        if (!preg_match('/^(0|[1-9]\d?)(?:\.(\d{1,2}))?$/D', $rate, $matches)) {
            return null;
        }
        $whole = (int) $matches[1];
        $fraction = (int) str_pad($matches[2] ?? '', 2, '0');
        $basisPoints = $whole * 100 + $fraction;
        return $basisPoints <= 10000 ? $basisPoints : null;
    }

    public function formatRateBasisPoints(int $basisPoints): string
    {
        return intdiv($basisPoints, 100).'.'.str_pad((string) ($basisPoints % 100), 2, '0', STR_PAD_LEFT);
    }
}