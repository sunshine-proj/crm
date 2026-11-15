<?php

namespace App\Service\Time;

use App\Entity\Task;
use App\Repository\TaskRepository;
use App\Repository\TimeEntryRepository;
use App\Service\Module\ModuleGate;

/**
 * Weekly workload per employee: time logged in each week (pending and approved) and planned work, which is the
 * remaining estimate of open assigned tasks placed in the week they are due (due date, else scheduled end).
 * A week is flagged as overloaded when logged plus planned minutes exceed the weekly capacity set for the Time tracking module.
 */
final class WorkloadService
{
    public const MAX_WEEKS = 26;

    public function __construct(
        private readonly TimeEntryRepository $timeEntryRepository,
        private readonly TaskRepository $taskRepository,
        private readonly ModuleGate $modules,
    ) {
    }

    /**
     * @return array<string, mixed> JSON response body
     * @throws \InvalidArgumentException when the period is invalid or too long
     */
    public function weekly(TimeScope $scope, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to, \DateTimeImmutable $today): array
    {
        $from = self::monday($from ?? $today->modify('-3 weeks'));
        $to = self::monday($to ?? $today->modify('+4 weeks'))->modify('+6 days');
        if ($from > $to) {
            throw new \InvalidArgumentException('The period start must not be after its end.');
        }
        $weekStarts = [];
        for ($week = $from; $week <= $to; $week = $week->modify('+1 week')) {
            $weekStarts[] = $week->format('Y-m-d');
        }
        if (count($weekStarts) > self::MAX_WEEKS) {
            throw new \InvalidArgumentException(sprintf('Choose a period of at most %d weeks.', self::MAX_WEEKS));
        }

        $capacity = 60 * (int) $this->modules->setting('time_tracking', 'weekly_capacity_hours');
        $employees = [];
        $cell = static function (array &$employees, int $id, string $email) use ($weekStarts): void {
            $employees[$id] ??= ['employee_id' => $id, 'employee_email' => $email, 'weeks' => array_fill_keys($weekStarts, ['logged' => 0, 'planned' => 0])];
        };
        foreach ($this->timeEntryRepository->dailyMinutesByUser($scope, $from, $to) as $row) {
            $week = self::monday($row['work_date'])->format('Y-m-d');
            $cell($employees, (int) $row['employee_id'], (string) $row['employee_email']);
            $employees[(int) $row['employee_id']]['weeks'][$week]['logged'] += (int) $row['minutes'];
        }

        $tasks = array_values(array_filter($this->taskRepository->findOpenAssignedWithEstimate(), static fn (Task $task): bool => self::visible($scope, $task)));
        $logged = $this->timeEntryRepository->minutesByTask(array_map(static fn (Task $task): int => (int) $task->getId(), $tasks));
        $unscheduled = 0;
        foreach ($tasks as $task) {
            $remaining = max(0, (int) $task->getEstimatedMinutes() - ($logged[(int) $task->getId()]['logged'] ?? 0));
            $due = $task->getDueDate() ?? $task->getEndDatetime();
            if ($remaining === 0) {
                continue;
            }
            if ($due === null) {
                ++$unscheduled;
                continue;
            }
            // Overdue work still has to be done: count it in the first week shown.
            $week = max(self::monday($due), $from)->format('Y-m-d');
            if ($week > end($weekStarts)) {
                continue;
            }
            $assignee = $task->getAssignee();
            $cell($employees, (int) $assignee->getId(), $assignee->getUserIdentifier());
            $employees[(int) $assignee->getId()]['weeks'][$week]['planned'] += $remaining;
        }

        usort($employees, static fn (array $a, array $b): int => strcmp($a['employee_email'], $b['employee_email']));

        return [
            'scope' => $scope->level(),
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'capacity_minutes' => $capacity,
            'weeks' => $weekStarts,
            'employees' => array_map(static function (array $employee) use ($capacity): array {
                $weeks = [];
                foreach ($employee['weeks'] as $start => $minutes) {
                    $weeks[] = [
                        'week_start' => $start,
                        'logged_minutes' => $minutes['logged'],
                        'planned_minutes' => $minutes['planned'],
                        'overloaded' => $minutes['logged'] + $minutes['planned'] > $capacity,
                    ];
                }

                return [
                    'employee_id' => $employee['employee_id'],
                    'employee_email' => $employee['employee_email'],
                    'logged_minutes' => array_sum(array_column($weeks, 'logged_minutes')),
                    'planned_minutes' => array_sum(array_column($weeks, 'planned_minutes')),
                    'weeks' => $weeks,
                ];
            }, $employees),
            'unscheduled_tasks' => $unscheduled,
            'basis' => 'Logged = pending and approved time by work date. Planned = remaining estimate (estimate minus logged time) of open assigned tasks, placed in the week the task is due; overdue tasks count in the first week shown. Capacity is the standard week set for the Time tracking module.',
        ];
    }

    private static function visible(TimeScope $scope, Task $task): bool
    {
        $projectId = $task->getProject()?->getId();

        return $task->getAssignee()?->getId() === $scope->userId() || ($projectId !== null ? $scope->seesWholeProject((int) $projectId) : $scope->seesEverything());
    }

    private static function monday(\DateTimeImmutable $date): \DateTimeImmutable
    {
        $day = $date->setTime(0, 0);

        return $day->modify(sprintf('-%d days', (int) $day->format('N') - 1));
    }
}
