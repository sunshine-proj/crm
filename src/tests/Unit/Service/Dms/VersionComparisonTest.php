<?php

namespace App\Tests\Unit\Service\Dms;

use App\Service\Dms\VersionComparison;
use PHPUnit\Framework\TestCase;

final class VersionComparisonTest extends TestCase
{
    public function test_sentences_are_aligned_and_changes_grouped(): void
    {
        $result = (new VersionComparison())->compare(
            'Scope applies to staff. Travel is booked by HR. Receipts are kept. Claims close monthly.',
            'Scope applies to staff. Travel is booked by the traveller. Receipts are kept. Claims close weekly. Audits happen yearly.',
        );

        self::assertTrue($result['comparable']);
        self::assertSame([
            ['op' => 'equal', 'text' => 'Scope applies to staff.'],
            ['op' => 'added', 'text' => 'Travel is booked by the traveller.'],
            ['op' => 'removed', 'text' => 'Travel is booked by HR.'],
            ['op' => 'equal', 'text' => 'Receipts are kept.'],
            ['op' => 'added', 'text' => 'Claims close weekly. Audits happen yearly.'],
            ['op' => 'removed', 'text' => 'Claims close monthly.'],
        ], $result['segments']);
        self::assertSame(3, $result['added']);
        self::assertSame(2, $result['removed']);
    }

    public function test_identical_missing_and_oversized_texts(): void
    {
        $comparison = new VersionComparison();
        self::assertSame([['op' => 'equal', 'text' => 'Same. Text.']], $comparison->compare('Same. Text.', 'Same. Text.')['segments']);
        self::assertFalse($comparison->compare(null, 'x')['comparable']);

        $many = static fn (string $word): string => implode(' ', array_map(static fn (int $i): string => "$word $i.", range(1, VersionComparison::MAX_UNITS + 1)));
        $tooMuch = $comparison->compare($many('a'), $many('b'));
        self::assertFalse($tooMuch['comparable']);
        self::assertStringContainsString('too extensive', (string) $tooMuch['reason']);

        $longButSimilar = $comparison->compare($many('a'), $many('a').' One more.');
        self::assertTrue($longButSimilar['comparable'], 'A long text with a small change is still compared.');
        self::assertSame(1, $longButSimilar['added']);
    }
}
