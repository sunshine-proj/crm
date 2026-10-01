<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Client;
use App\Entity\Project;
use App\Entity\ProjectStatus;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProjectTest extends TestCase
{
    #[Test]
    public function it_starts_planned_and_can_be_associated_with_a_client(): void
    {
        $client = new Client();
        $project = (new Project())->setClient($client);

        self::assertSame(ProjectStatus::Planned, $project->getStatus());
        self::assertSame($client, $project->getClient());
    }

    #[Test]
    public function it_accepts_a_valid_planned_date_range(): void
    {
        $start = new DateTimeImmutable('2026-10-01');
        $end = new DateTimeImmutable('2026-10-02');
        $project = (new Project())->setPlannedDates($start, $end);

        self::assertSame($start, $project->getStartDate());
        self::assertSame($end, $project->getEndDate());
    }

    #[Test]
    public function it_rejects_an_end_date_before_the_start_date(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Project())->setPlannedDates(
            new DateTimeImmutable('2026-10-02'),
            new DateTimeImmutable('2026-10-01'),
        );
    }
}
