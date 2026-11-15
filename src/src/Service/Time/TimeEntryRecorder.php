<?php

namespace App\Service\Time;

use App\Dto\TimeEntryInput;
use App\Entity\TimeEntry;
use App\Entity\User;
use App\Repository\ProjectRepository;
use App\Repository\TaskRepository;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns validated time-entry input into a new pending entry with immutable billing and cost snapshots. Shared by the
 * single-entry endpoint, the weekly timesheet batch and the task timer, so all of them follow the same rules.
 */
final class TimeEntryRecorder
{
    public function __construct(
        private readonly ProjectRepository $projectRepository,
        private readonly TaskRepository $taskRepository,
    ) {
    }

    /** @throws TimeEntryRejected */
    public function build(TimeEntryInput $input, User $user): TimeEntry
    {
        if ($input->project_id === null || $input->work_date === null || $input->duration_minutes === null || $input->description === null || trim($input->description) === '') {
            throw new TimeEntryRejected('Project, work date, duration, and description are required.', Response::HTTP_BAD_REQUEST);
        }
        $project = $this->projectRepository->find($input->project_id);
        if ($project === null) {
            throw new TimeEntryRejected('Project not found.', Response::HTTP_NOT_FOUND);
        }
        $workDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $input->work_date);
        if ($workDate === false || $workDate->format('Y-m-d') !== $input->work_date) {
            throw new TimeEntryRejected('Work date must use YYYY-MM-DD.', Response::HTTP_BAD_REQUEST);
        }
        $task = null;
        if ($input->task_id !== null) {
            $task = $this->taskRepository->find($input->task_id);
            if ($task === null || $task->getProject()?->getId() !== $project->getId()) {
                throw new TimeEntryRejected('Task must belong to the selected project.', Response::HTTP_BAD_REQUEST);
            }
        }
        if ($input->duration_minutes < 1 || $input->duration_minutes > 1440 || mb_strlen(trim($input->description)) > 255) {
            throw new TimeEntryRejected('Duration must be 1-1440 minutes and description at most 255 characters.', Response::HTTP_BAD_REQUEST);
        }
        $billingAmount = null;
        $billingCurrency = null;
        if ($input->billable) {
            if ($project->getHourlyRateMinor() === null || $project->getBillingCurrency() === null) {
                throw new TimeEntryRejected('Configure a project billing rate before recording billable time.', Response::HTTP_CONFLICT);
            }
            $billingAmount = $this->rateForDuration($project->getHourlyRateMinor(), $input->duration_minutes);
            $billingCurrency = $project->getBillingCurrency();
        }
        $costAmount = null;
        $costCurrency = null;
        if ($user->getHourlyCostRateMinor() !== null && $user->getHourlyCostCurrency() !== null) {
            $costAmount = $this->rateForDuration($user->getHourlyCostRateMinor(), $input->duration_minutes);
            $costCurrency = $user->getHourlyCostCurrency();
        }

        return new TimeEntry($project, $task, $user, $workDate, $input->duration_minutes, $input->billable, trim($input->description), $costAmount, $costCurrency, $billingAmount, $billingCurrency);
    }

    /** Hourly rate in minor units applied to whole minutes, rounded half up, without floating-point arithmetic. */
    public function rateForDuration(int $hourlyRateMinor, int $durationMinutes): int
    {
        $quotient = intdiv($hourlyRateMinor, 60);
        $remainder = $hourlyRateMinor % 60;
        if ($quotient > 0 && $durationMinutes > intdiv(PHP_INT_MAX, $quotient)) {
            throw new TimeEntryRejected('Time-entry rate calculation exceeds the supported amount range.', Response::HTTP_BAD_REQUEST);
        }
        $wholeAmount = $quotient * $durationMinutes;
        $fractionAmount = intdiv($remainder * $durationMinutes + 30, 60);
        if ($fractionAmount > PHP_INT_MAX - $wholeAmount) {
            throw new TimeEntryRejected('Time-entry rate calculation exceeds the supported amount range.', Response::HTTP_BAD_REQUEST);
        }

        return $wholeAmount + $fractionAmount;
    }
}
