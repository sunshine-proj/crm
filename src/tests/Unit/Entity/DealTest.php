<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Client;
use App\Entity\Deal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DealTest extends TestCase
{
    private function deal(?int $amount = 10000, ?string $currency = 'EUR'): Deal
    {
        return (new Deal(new Client(), 'Website rebuild'))->describe('Website rebuild', $amount, $currency, null, null);
    }

    #[Test]
    public function a_new_deal_starts_as_a_lead_with_the_lead_probability(): void
    {
        $deal = $this->deal();

        self::assertSame('lead', $deal->getStage());
        self::assertSame(10, $deal->probability());
        self::assertTrue($deal->isOpen());
    }

    #[Test]
    public function open_deals_move_freely_between_open_stages(): void
    {
        $deal = $this->deal();
        $deal->moveTo('negotiation');
        $deal->moveTo('qualified');

        self::assertSame('qualified', $deal->getStage());
        self::assertSame(25, $deal->probability());
    }

    #[Test]
    public function winning_requires_a_value_and_closes_the_deal(): void
    {
        $unvalued = $this->deal(null, null);
        try {
            $unvalued->moveTo('won');
            self::fail('A deal without value cannot be won.');
        } catch (\DomainException) {
            self::assertTrue($unvalued->isOpen());
        }

        $deal = $this->deal();
        $deal->moveTo('won');
        self::assertFalse($deal->isOpen());
        self::assertNotNull($deal->getClosedAt());
        self::assertSame(0, $deal->probability());
    }

    #[Test]
    public function losing_requires_a_reason_and_is_final(): void
    {
        $deal = $this->deal();
        try {
            $deal->moveTo('lost', '  ');
            self::fail('A lost deal needs a reason.');
        } catch (\DomainException) {
        }
        $deal->moveTo('lost', 'Budget cut');
        self::assertSame('Budget cut', $deal->getLostReason());

        $this->expectException(\DomainException::class);
        $deal->moveTo('lead');
    }

    #[Test]
    public function the_same_or_an_unknown_stage_is_rejected(): void
    {
        $deal = $this->deal();
        foreach (['lead', 'archived'] as $stage) {
            try {
                $deal->moveTo($stage);
                self::fail($stage);
            } catch (\DomainException) {
            }
        }
        self::assertSame('lead', $deal->getStage());
    }

    #[Test]
    public function amount_and_currency_must_be_given_together_and_not_be_negative(): void
    {
        foreach ([[100, null], [null, 'EUR'], [-1, 'EUR']] as [$amount, $currency]) {
            try {
                $this->deal($amount, $currency);
                self::fail('Invalid value accepted.');
            } catch (\InvalidArgumentException) {
            }
        }
        self::assertSame(0, $this->deal(0, 'EUR')->getAmountMinor());
    }
}
