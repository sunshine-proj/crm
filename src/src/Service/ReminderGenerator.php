<?php

namespace App\Service;

use App\Entity\Notification;
use App\Entity\Project;
use App\Entity\ProjectStatus;
use App\Entity\ReminderPreference;
use App\Entity\Task;
use App\Entity\TaskStatus;
use App\Entity\User;
use App\Repository\NotificationRepository;
use App\Repository\ProjectRepository;
use App\Repository\ReminderPreferenceRepository;
use App\Repository\TaskRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Creates in-app reminders for due-soon and overdue tasks and projects, and escalates work that stays overdue to
 * the responsible manager (or to administrators). Idempotent: every notification has a deterministic dedupe key,
 * so running the scheduler repeatedly never repeats a reminder for the same deadline.
 *
 * Deadline rules follow the project-management overview: a task is due on its due date, or on its scheduled end
 * date when it has none; a project is due on its planned end date. Finished and cancelled work is ignored.
 */
final class ReminderGenerator
{
    /** @var array<string, true> dedupe keys created in this run, per user id */
    private array $created = [];
    /** @var list<User>|null */
    private ?array $admins = null;
    /** @var array<int, ReminderPreference|null> */
    private array $preferences = [];

    public function __construct(
        private readonly TaskRepository $taskRepository,
        private readonly ProjectRepository $projectRepository,
        private readonly UserRepository $userRepository,
        private readonly ReminderPreferenceRepository $preferenceRepository,
        private readonly NotificationRepository $notificationRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /** @return array{due_soon: int, overdue: int, escalations: int} number of notifications created */
    public function run(\DateTimeImmutable $now): array
    {
        $today = $now->setTime(0, 0);
        $this->created = [];
        $counts = [Notification::DUE_SOON => 0, Notification::OVERDUE => 0, Notification::ESCALATION => 0];

        foreach ($this->taskRepository->findAll() as $task) {
            if (in_array($task->getStatus(), [TaskStatus::Done, TaskStatus::Cancelled], true)) {
                continue;
            }
            $due = $task->getDueDate() ?? $task->getEndDatetime();
            $assignee = $task->getAssignee();
            if ($due === null || $assignee === null) {
                continue;
            }
            $this->remind($counts, $assignee, 'task', (int) $task->getId(), $task->getProject()?->getId(), sprintf('Task "%s"', $task->getTitle()), $due->setTime(0, 0), $today, 'lead_task', $task->getProject()?->getManager());
        }
        foreach ($this->projectRepository->findAll() as $project) {
            if (in_array($project->getStatus(), [ProjectStatus::Completed, ProjectStatus::Cancelled], true)) {
                continue;
            }
            $due = $project->getEndDate();
            $manager = $project->getManager();
            if ($due === null || $manager === null) {
                continue;
            }
            $this->remind($counts, $manager, 'project', (int) $project->getId(), $project->getId(), sprintf('Project "%s"', $project->getTitle()), $due->setTime(0, 0), $today, 'lead_project', null);
        }
        $this->entityManager->flush();

        return ['due_soon' => $counts[Notification::DUE_SOON], 'overdue' => $counts[Notification::OVERDUE], 'escalations' => $counts[Notification::ESCALATION]];
    }

    /** @param array<string, int> $counts */
    private function remind(array &$counts, User $owner, string $type, int $id, ?int $projectId, string $label, \DateTimeImmutable $due, \DateTimeImmutable $today, string $leadKind, ?User $escalateTo): void
    {
        $preference = $this->preference($owner);
        $enabled = $preference?->isEnabled() ?? true;
        $lead = $leadKind === 'lead_task'
            ? ($preference?->getTaskLeadDays() ?? ReminderPreference::DEFAULT_TASK_LEAD_DAYS)
            : ($preference?->getProjectLeadDays() ?? ReminderPreference::DEFAULT_PROJECT_LEAD_DAYS);
        $daysUntilDue = (int) $today->diff($due)->format('%r%a');
        $dueText = $due->format('Y-m-d');

        if ($daysUntilDue >= 0 && $daysUntilDue <= $lead && $enabled) {
            $when = $daysUntilDue === 0 ? 'today' : sprintf('in %d day%s', $daysUntilDue, $daysUntilDue === 1 ? '' : 's');
            $this->notify($counts, $owner, Notification::DUE_SOON, $type, $id, $projectId, sprintf('%s is due %s (%s).', $label, $when, $dueText), sprintf('%s:%d:soon:%s', $type, $id, $dueText));
        }
        if ($daysUntilDue >= 0) {
            return;
        }
        $overdueDays = -$daysUntilDue;
        if ($enabled) {
            $this->notify($counts, $owner, Notification::OVERDUE, $type, $id, $projectId, sprintf('%s is overdue since %s.', $label, $dueText), sprintf('%s:%d:overdue:%s', $type, $id, $dueText));
        }
        $escalateAfter = $preference?->getEscalateAfterDays() ?? ReminderPreference::DEFAULT_ESCALATE_AFTER_DAYS;
        if ($escalateAfter < 1 || $overdueDays < $escalateAfter) {
            return;
        }
        $recipients = $escalateTo !== null && $escalateTo->getId() !== $owner->getId() ? [$escalateTo] : $this->admins();
        foreach ($recipients as $recipient) {
            if ($recipient->getId() === $owner->getId() || !($this->preference($recipient)?->isEnabled() ?? true)) {
                continue;
            }
            $this->notify($counts, $recipient, Notification::ESCALATION, $type, $id, $projectId, sprintf('Escalation: %s assigned to %s has been overdue for %d days (due %s).', $label, $owner->getUserIdentifier(), $overdueDays, $dueText), sprintf('%s:%d:escalation:%s', $type, $id, $dueText));
        }
    }

    /** @param array<string, int> $counts */
    private function notify(array &$counts, User $user, string $kind, string $type, int $id, ?int $projectId, string $message, string $dedupeKey): void
    {
        $local = $user->getId().'|'.$dedupeKey;
        if (isset($this->created[$local]) || $this->notificationRepository->exists($user, $dedupeKey)) {
            return;
        }
        $this->created[$local] = true;
        $this->entityManager->persist(new Notification($user, $kind, $type, $id, $projectId, $message, $dedupeKey));
        ++$counts[$kind];
    }

    private function preference(User $user): ?ReminderPreference
    {
        $id = (int) $user->getId();
        if (!array_key_exists($id, $this->preferences)) {
            $this->preferences[$id] = $this->preferenceRepository->findForUser($user);
        }

        return $this->preferences[$id];
    }

    /** @return list<User> */
    private function admins(): array
    {
        return $this->admins ??= array_values(array_filter($this->userRepository->findAll(), static fn (User $user): bool => in_array('ROLE_ADMIN', $user->getRoles(), true)));
    }
}
