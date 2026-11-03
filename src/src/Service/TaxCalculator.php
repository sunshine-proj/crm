<?php

namespace App\Service;

/**
 * Integer tax arithmetic in minor units. Rates are basis points (2100 = 21.00%). Rounding is half-up.
 *
 * per_rate_group: net amounts are summed per rate and tax is rounded once per rate (the usual invoice-level method).
 * per_line:       tax is rounded on every line and summed.
 * Which method applies is for the jurisdiction's adviser to confirm (see JurisdictionValidation).
 */
final class TaxCalculator
{
    /**
     * @param list<array{net_minor: int, rate_bp: int, code: string|null}> $lines
     * @return array{
     *   lines: list<int>,
     *   breakdown: list<array{code: string|null, rate_bp: int, net_minor: int, tax_minor: int}>,
     *   net_minor: int, tax_minor: int, gross_minor: int
     * }
     */
    public function calculate(array $lines, string $rounding): array
    {
        if (!in_array($rounding, JurisdictionCatalog::ROUNDING_MODES, true)) {
            throw new \InvalidArgumentException('Unsupported tax rounding method.');
        }
        $groups = [];
        $lineTax = [];
        foreach ($lines as $index => $line) {
            if ($line['net_minor'] < 0 || $line['rate_bp'] < 0 || $line['rate_bp'] > 10000) {
                throw new \InvalidArgumentException('Net amounts must be non-negative and rates between 0% and 100%.');
            }
            $key = $line['rate_bp'].'|'.($line['code'] ?? '');
            $groups[$key] ??= ['code' => $line['code'], 'rate_bp' => $line['rate_bp'], 'net_minor' => 0, 'tax_minor' => 0];
            if ($line['net_minor'] > PHP_INT_MAX - $groups[$key]['net_minor']) {
                throw new \OverflowException('Invoice amount is too large.');
            }
            $groups[$key]['net_minor'] += $line['net_minor'];
            $lineTax[$index] = $this->taxOn($line['net_minor'], $line['rate_bp']);
        }
        $net = 0;
        $tax = 0;
        foreach ($groups as $key => &$group) {
            $group['tax_minor'] = $rounding === 'per_rate_group'
                ? $this->taxOn($group['net_minor'], $group['rate_bp'])
                : array_sum(array_map(fn (int $i): int => $lineTax[$i], array_keys(array_filter($lines, static fn (array $l): bool => $l['rate_bp'].'|'.($l['code'] ?? '') === $key))));
            $net += $group['net_minor'];
            $tax += $group['tax_minor'];
        }
        unset($group);
        if ($net > PHP_INT_MAX - $tax) {
            throw new \OverflowException('Invoice amount is too large.');
        }

        return ['lines' => array_values($lineTax), 'breakdown' => array_values($groups), 'net_minor' => $net, 'tax_minor' => $tax, 'gross_minor' => $net + $tax];
    }

    /** net × rate ÷ 10 000, rounded half up. */
    public function taxOn(int $netMinor, int $rateBp): int
    {
        if ($netMinor > 0 && $rateBp > 0 && $netMinor > intdiv(PHP_INT_MAX - 5000, $rateBp)) {
            throw new \OverflowException('Invoice amount is too large.');
        }

        return intdiv($netMinor * $rateBp + 5000, 10000);
    }
}
