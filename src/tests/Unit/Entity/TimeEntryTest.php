<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Project;
use App\Entity\TimeEntry;
use App\Entity\User;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TimeEntryTest extends TestCase
{
    #[Test]
    public function it_starts_pending_with_immutable_rate_snapshots(): void
    {
        $entry = new TimeEntry(
            new Project(),
            null,
            (new User())->setEmail('member@example.test'),
            new DateTimeImmutable('2026-10-01'),
            90,
            true,
            'Implementation work',
            4500,
            'EUR',
            9000,
            'EUR',
        );

        self::assertSame('pending_approval', $entry->getApprovalStatus());
        self::assertSame(4500, $entry->getCostMinor());
        self::assertSame('EUR', $entry->getCostCurrency());
        self::assertSame(9000, $entry->getBillingAmountMinor());
        self::assertSame('EUR', $entry->getBillingCurrency());
    }

    #[Test]
    public function it_records_one_terminal_approval_decision(): void
    {
        $actor = (new User())->setEmail('manager@example.test');
        $entry = new TimeEntry(
            new Project(),
            null,
            (new User())->setEmail('member@example.test'),
            new DateTimeImmutable('2026-10-01'),
            60,
            false,
            'Internal work',
        );

        $entry->decide('approved', $actor, 'Reviewed');

        self::assertSame('approved', $entry->getApprovalStatus());
        self::assertSame($actor, $entry->getDecidedBy());
        self::assertSame('Reviewed', $entry->getDecisionComment());
        $this->expectException(DomainException::class);
        $entry->decide('rejected', $actor);
    }

    private function entry(): TimeEntry
    {
        return new TimeEntry(new Project(), null, (new User())->setEmail('member@example.test'), new DateTimeImmutable('2026-10-01'), 60, true, 'Work', 100, 'EUR', 200, 'EUR');
    }

    #[Test]
    public function approved_uninvoiced_time_can_be_reopened_and_decided_again(): void
    {
        $entry = $this->entry();
        $manager = (new User())->setEmail('pm@example.test');
        $entry->decide('approved', $manager, 'ok');

        $entry->reopen();

        self::assertSame('pending_approval', $entry->getApprovalStatus());
        self::assertNull($entry->getDecidedBy());
        self::assertSame(200, $entry->getBillingAmountMinor(), 'rate snapshots are not rewritten');
        $entry->decide('rejected', $manager, 'too long');
        self::assertSame('rejected', $entry->getApprovalStatus());
    }

    #[Test]
    public function pending_and_rejected_time_cannot_be_reopened(): void
    {
        $this->expectException(DomainException::class);
        $this->entry()->reopen();
    }

    #[Test]
    public function invoiced_time_cannot_be_reopened_or_withdrawn(): void
    {
        $entry = $this->entry();
        $entry->decide('approved', (new User())->setEmail('pm@example.test'));
        $entry->setInvoice(new \App\Entity\Invoice());

        self::assertFalse($entry->isWithdrawable());
        $this->expectException(DomainException::class);
        $entry->reopen();
    }

    #[Test]
    public function only_pending_or_rejected_uninvoiced_time_is_withdrawable(): void
    {
        $entry = $this->entry();
        self::assertTrue($entry->isWithdrawable());
        $entry->decide('approved', (new User())->setEmail('pm@example.test'));
        self::assertFalse($entry->isWithdrawable());
    }
}
