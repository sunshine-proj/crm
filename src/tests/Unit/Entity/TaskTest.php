<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Project;
use App\Entity\Task;
use App\Entity\TaskPriority;
use App\Entity\TaskStatus;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TaskTest extends TestCase
{
    #[Test]
    public function it_starts_open_with_normal_priority_and_can_belong_to_a_project(): void
    {
        $project = new Project();
        $task = (new Task())->setProject($project);

        self::assertSame(TaskStatus::Todo, $task->getStatus());
        self::assertSame(TaskPriority::Normal, $task->getPriority());
        self::assertTrue($task->isOpen());
        self::assertSame($project, $task->getProject());
    }

    #[Test]
    public function completed_tasks_are_not_open(): void
    {
        $task = (new Task())->setStatus(TaskStatus::Done);

        self::assertFalse($task->isOpen());
    }

    #[Test]
    public function it_rejects_an_end_time_before_the_start_time(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Task())->setSchedule(
            new DateTimeImmutable('2026-10-02T10:00:00+00:00'),
            new DateTimeImmutable('2026-10-02T09:00:00+00:00'),
        );
    }
}
