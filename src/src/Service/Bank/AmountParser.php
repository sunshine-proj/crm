<?php

namespace App\Service\Bank;

/** Parses signed decimal amounts from bank files into integer minor units (two decimals) without floating point. */
final class AmountParser
{
    public function parseMinor(string $value): ?int
    {
        $value = trim(str_replace(["\u{00A0}", ' '], '', $value));
        $negative = false;
        if ($value !== '' && ($value[0] === '-' || $value[0] === '+')) {
            $negative = $value[0] === '-';
            $value = substr($value, 1);
        } elseif (str_starts_with($value, '(') && str_ends_with($value, ')')) {
            $negative = true;
            $value = substr($value, 1, -1);
        }
        // "1.234,56" and "1,234.56" are both accepted. Thousands separators must be consistent and, when a decimal
        // part follows, the decimal separator must be the other character.
        if (preg_match('/^(?<whole>\d{1,3}(?<group>[.,])\d{3}(?:\k<group>\d{3})*|\d+)(?:(?<decimal>[.,])(?<fraction>\d{1,2}))?$/D', $value, $m) !== 1) {
            return null;
        }
        if (($m['group'] ?? '') !== '' && ($m['decimal'] ?? '') === ($m['group'] ?? '')) {
            return null;
        }
        $whole = preg_replace('/[.,]/', '', $m['whole']);
        $fraction = str_pad($m['fraction'] ?? '', 2, '0');
        if (strlen($whole) > 16) {
            return null;
        }
        $minor = (int) $whole * 100 + (int) $fraction;

        return $negative ? -$minor : $minor;
    }
}
