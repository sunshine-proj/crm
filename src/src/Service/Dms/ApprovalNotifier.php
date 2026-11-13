<?php

namespace App\Service\Dms;

use App\Entity\Document;
use App\Entity\Notification;
use App\Entity\User;
use App\Repository\NotificationRepository;
use App\Service\Org\OrgChart;
use Doctrine\ORM\EntityManagerInterface;

/**
 * In-app notifications for approval work: a request goes to the assignee and to whoever deputizes for them today;
 * outcomes go to the author. Each notification has a dedupe key so a retried request never repeats one.
 * (E-mail delivery is still blocked, see the README.)
 */
final class ApprovalNotifier
{
    public const REQUEST = 'approval';
    public const OUTCOME = 'decision';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly NotificationRepository $notificationRepository,
    ) {
    }

    public function request(User $assignee, Document $document, string $message, string $dedupeKey, OrgChart $chart): void
    {
        $this->notify($assignee, self::REQUEST, $document, $message, $dedupeKey);
        $deputy = $chart->deputyFor($assignee);
        if ($deputy !== null) {
            $this->notify($deputy, self::REQUEST, $document, sprintf('On behalf of %s: %s', $assignee->getUserIdentifier(), $message), $dedupeKey.':deputy');
        }
    }

    public function outcome(User $recipient, Document $document, string $message, string $dedupeKey): void
    {
        $this->notify($recipient, self::OUTCOME, $document, $message, $dedupeKey);
    }

    private function notify(User $user, string $kind, Document $document, string $message, string $dedupeKey): void
    {
        $dedupeKey = mb_substr($dedupeKey, 0, 120);
        if ($this->notificationRepository->exists($user, $dedupeKey)) {
            return;
        }
        $this->entityManager->persist(new Notification($user, $kind, 'document', (int) $document->getId(), null, mb_substr($message, 0, 255), $dedupeKey));
    }

    /** @return list<string> */
    public static function kinds(): array
    {
        return [self::REQUEST, self::OUTCOME, Notification::DUE_SOON, Notification::OVERDUE, Notification::ESCALATION];
    }
}
