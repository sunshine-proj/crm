<?php

namespace App\Tests\Unit\Service;

use App\Service\FinanceCatalog;
use PHPUnit\Framework\TestCase;

final class FinanceCatalogTest extends TestCase
{
    public function test_supported_currencies_are_explicit_and_single_currency_only(): void
    {
        $catalog = new FinanceCatalog();

        self::assertTrue($catalog->supportsCurrency('EUR'));
        self::assertTrue($catalog->supportsCurrency('GBP'));
        self::assertTrue($catalog->supportsCurrency('USD'));
        self::assertFalse($catalog->supportsCurrency('JPY'));
        self::assertCount(3, $catalog->currencies());
    }

    public function test_tax_percent_parser_uses_basis_points_and_bounds(): void
    {
        $catalog = new FinanceCatalog();

        self::assertSame(2100, $catalog->parseRateBasisPoints('21'));
        self::assertSame(825, $catalog->parseRateBasisPoints('8.25'));
        self::assertSame('8.25', $catalog->formatRateBasisPoints(825));
        self::assertNull($catalog->parseRateBasisPoints('-1'));
        self::assertNull($catalog->parseRateBasisPoints('100.01'));
        self::assertNull($catalog->parseRateBasisPoints('1.234'));
    }
}