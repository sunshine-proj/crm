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
}
