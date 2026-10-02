<?php

namespace App\Service;

final class InvoiceMoney
{
	public function parseMinor(string $amount): ?int
	{
		if (!preg_match('/^(0|[1-9]\d*)(?:\.(\d{1,2}))?$/D', $amount, $matches)) {
			return null;
		}

		$whole = filter_var($matches[1], FILTER_VALIDATE_INT);
		if ($whole === false || $whole > intdiv(PHP_INT_MAX, 100)) {
			return null;
		}
		$fraction = (int) str_pad($matches[2] ?? '', 2, '0');
		$minor = $whole * 100;
		if ($fraction > PHP_INT_MAX - $minor) {
			return null;
		}

		return $minor + $fraction;
	}

	public function formatMinor(int $amount): string
	{
		return intdiv($amount, 100).'.'.str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
	}
}