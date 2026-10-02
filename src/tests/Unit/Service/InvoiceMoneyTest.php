<?php

namespace App\Tests\Unit\Service;

use App\Service\InvoiceMoney;
use PHPUnit\Framework\TestCase;

final class InvoiceMoneyTest extends TestCase
{
	public function test_parses_and_formats_minor_units_without_float_rounding(): void
	{
		$money = new InvoiceMoney();

		self::assertSame(12340, $money->parseMinor('123.4'));
		self::assertSame(9, $money->parseMinor('0.09'));
		self::assertSame('123.40', $money->formatMinor(12340));
	}

	public function test_rejects_invalid_or_overflowing_amounts(): void
	{
		$money = new InvoiceMoney();

		self::assertNull($money->parseMinor('-1.00'));
		self::assertNull($money->parseMinor('1.001'));
		self::assertNull($money->parseMinor('01.00'));
		self::assertNull($money->parseMinor((string) PHP_INT_MAX));
	}
}