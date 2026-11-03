<?php

namespace App\Tests\Unit\Service;

use App\Service\TaxCalculator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TaxCalculatorTest extends TestCase
{
    private TaxCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new TaxCalculator();
    }

    #[Test]
    public function tax_is_rounded_half_up_in_minor_units(): void
    {
        self::assertSame(2, $this->calculator->taxOn(10, 2100));   // 0.021 -> 0.02
        self::assertSame(1, $this->calculator->taxOn(5, 1000));    // 0.005 -> 0.01 (half up)
        self::assertSame(0, $this->calculator->taxOn(4, 1000));    // 0.004 -> 0.00
        self::assertSame(2100, $this->calculator->taxOn(10000, 2100));
        self::assertSame(0, $this->calculator->taxOn(12345, 0));
    }

    #[Test]
    public function the_rounding_method_changes_the_result_when_lines_round_differently(): void
    {
        // Three lines of 0.25 at 10%: each is 0.025 -> 0.03 when rounded per line, but 0.75 x 10% = 0.075 -> 0.08 per rate group.
        $lines = array_fill(0, 3, ['net_minor' => 25, 'rate_bp' => 1000, 'code' => 'STD']);

        $perLine = $this->calculator->calculate($lines, 'per_line');
        $perGroup = $this->calculator->calculate($lines, 'per_rate_group');

        self::assertSame(9, $perLine['tax_minor']);
        self::assertSame(8, $perGroup['tax_minor']);
        self::assertSame(75, $perLine['net_minor']);
        self::assertSame(75 + 8, $perGroup['gross_minor']);
    }

    #[Test]
    public function the_breakdown_groups_lines_by_rate_and_code(): void
    {
        $result = $this->calculator->calculate([
            ['net_minor' => 10000, 'rate_bp' => 2100, 'code' => 'STD'],
            ['net_minor' => 5000, 'rate_bp' => 1200, 'code' => 'RED'],
            ['net_minor' => 2500, 'rate_bp' => 2100, 'code' => 'STD'],
            ['net_minor' => 700, 'rate_bp' => 0, 'code' => 'EXEMPT'],
        ], 'per_rate_group');

        self::assertCount(3, $result['breakdown']);
        self::assertSame(12500, $result['breakdown'][0]['net_minor']);
        self::assertSame(2625, $result['breakdown'][0]['tax_minor']);
        self::assertSame(600, $result['breakdown'][1]['tax_minor']);
        self::assertSame(0, $result['breakdown'][2]['tax_minor']);
        self::assertSame(18200, $result['net_minor']);
        self::assertSame(18200 + 2625 + 600, $result['gross_minor']);
    }

    #[Test]
    public function an_unknown_rounding_method_or_invalid_input_is_rejected(): void
    {
        foreach ([['nearest', [['net_minor' => 1, 'rate_bp' => 100, 'code' => null]]], ['per_line', [['net_minor' => -1, 'rate_bp' => 100, 'code' => null]]], ['per_line', [['net_minor' => 1, 'rate_bp' => 10001, 'code' => null]]]] as [$mode, $lines]) {
            try {
                $this->calculator->calculate($lines, $mode);
                self::fail('Invalid input must be rejected.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    #[Test]
    public function amounts_that_would_overflow_are_refused(): void
    {
        $this->expectException(\OverflowException::class);
        $this->calculator->taxOn(PHP_INT_MAX, 2100);
    }
}
