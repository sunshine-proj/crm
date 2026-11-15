<?php

namespace App\Service\Time;

use App\Entity\Notification;
use App\Repository\NotificationRepository;
use App\Repository\ReminderPreferenceRepository;
use App\Repository\TimeEntryRepository;
use App\Repository\UserRepository;
use App\Service\Module\ModuleGate;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reminds people who usually log time (any entry in the four weeks before) that they logged nothing on the previous
 * working day (Monday to Friday). Leave and public holidays are not modelled, so people can turn reminders off in
 * their notification preferences. Idempotent: one notification per person and day (`missing_time:<date>`).
 */
final class MissingTimeReminder
{
    public const ACTIVE_WINDOW_DAYS = 28;

    public function __construct(
        private readonly TimeEntryRepository $timeEntryRepository,
        private readonly UserRepository $userRepository,
        private readonly ReminderPreferenceRepository $preferenceRepository,
        private readonly NotificationRepository $notificationRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ModuleGate $modules,
    ) {
    }

    /** @return int number of notifications created */
    public function run(\DateTimeImmutable $now): int
    {
        if (!$this->modules->isEnabled('time_tracking') || $this->modules->setting('time_tracking', 'missing_time_reminders') !== true) {
            return 0;
        }
        $day = self::previousWorkingDay($now->setTime(0, 0));
        $dedupeKey = 'missing_time:'.$day->format('Y-m-d');
        $created = 0;
        foreach ($this->timeEntryRepository->userIdsWithTimeBetween($day->modify(sprintf('-%d days', self::ACTIVE_WINDOW_DAYS)), $day->modify('-1 day')) as $userId) {
            $user = $this->userRepository->find($userId);
            if ($user === null || !$this->modules->isAvailableTo('time_tracking', $user) || !($this->preferenceRepository->findForUser($user)?->isEnabled() ?? true)) {
                continue;
            }
            if ($this->timeEntryRepository->hasTimeOn($user, $day) || $this->notificationRepository->exists($user, $dedupeKey)) {
                continue;
            }
            $this->entityManager->persist(new Notification($user, Notification::MISSING_TIME, 'timesheet', 0, null, sprintf('No time logged for %s (%s). Add it in your timesheet.', $day->format('l'), $day->format('Y-m-d')), $dedupeKey));
            ++$created;
        }
        $this->entityManager->flush();

        return $created;
    }

    public static function previousWorkingDay(\DateTimeImmutable $today): \DateTimeImmutable
    {
        $day = $today->modify('-1 day');
        while ((int) $day->format('N') > 5) {
            $day = $day->modify('-1 day');
        }

        return $day;
    }
}
