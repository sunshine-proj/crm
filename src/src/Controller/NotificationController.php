<?php

namespace App\Controller;

use App\Dto\ReminderPreferenceInput;
use App\Entity\Notification;
use App\Entity\ReminderPreference;
use App\Entity\User;
use App\Repository\NotificationRepository;
use App\Repository\ReminderPreferenceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Notifications are always scoped to the signed-in user. */
#[Route('/api/notifications', name: 'api_notifications_')]
#[IsGranted('ROLE_USER')]
final class NotificationController extends AbstractController
{
    public function __construct(
        private readonly NotificationRepository $notificationRepository,
        private readonly ReminderPreferenceRepository $preferenceRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $user = $this->currentUser();
        $limit = $request->query->get('limit', '30');
        if (!ctype_digit((string) $limit) || (int) $limit < 1 || (int) $limit > 100) {
            return $this->json(['error' => 'limit must be between 1 and 100.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        return $this->json([
            'unread_count' => $this->notificationRepository->unreadCount($user),
            'items' => array_map($this->serialize(...), $this->notificationRepository->inbox($user, $request->query->getBoolean('unread'), (int) $limit)),
        ]);
    }

    #[Route('/{id<\d+>}/read', name: 'read', methods: ['POST'])]
    public function markRead(int $id): JsonResponse
    {
        $notification = $this->notificationRepository->find($id);
        // Another user's notification is reported as missing so ids cannot be probed.
        if (!$notification instanceof Notification || $notification->getUser()->getId() !== $this->currentUser()->getId()) {
            return $this->json(['error' => 'Notification not found.'], JsonResponse::HTTP_NOT_FOUND);
        }
        $notification->markRead();
        $this->entityManager->flush();

        return $this->json($this->serialize($notification));
    }

    #[Route('/read-all', name: 'read_all', methods: ['POST'])]
    public function markAllRead(): Response
    {
        foreach ($this->notificationRepository->inbox($this->currentUser(), true, 500) as $notification) {
            $notification->markRead();
        }
        $this->entityManager->flush();

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/preferences', name: 'preferences_show', methods: ['GET'])]
    public function preferences(): JsonResponse
    {
        return $this->json($this->serializePreference($this->preferenceRepository->findForUser($this->currentUser())));
    }

    #[Route('/preferences', name: 'preferences_update', methods: ['PUT'])]
    public function updatePreferences(#[MapRequestPayload] ReminderPreferenceInput $input): JsonResponse
    {
        $user = $this->currentUser();
        $preference = $this->preferenceRepository->findForUser($user) ?? new ReminderPreference($user);
        try {
            $preference->configure($input->enabled, $input->task_lead_days, $input->project_lead_days, $input->escalate_after_days);
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['error' => $exception->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
        }
        $this->entityManager->persist($preference);
        $this->entityManager->flush();

        return $this->json($this->serializePreference($preference));
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        // The token's user may be a detached copy; relate new rows to the managed instance.
        return $this->entityManager->getReference(User::class, $user->getId());
    }

    /** @return array<string, mixed> */
    private function serializePreference(?ReminderPreference $preference): array
    {
        return [
            'enabled' => $preference?->isEnabled() ?? true,
            'task_lead_days' => $preference?->getTaskLeadDays() ?? ReminderPreference::DEFAULT_TASK_LEAD_DAYS,
            'project_lead_days' => $preference?->getProjectLeadDays() ?? ReminderPreference::DEFAULT_PROJECT_LEAD_DAYS,
            'escalate_after_days' => $preference?->getEscalateAfterDays() ?? ReminderPreference::DEFAULT_ESCALATE_AFTER_DAYS,
            'delivery' => 'in_app',
        ];
    }

    /** @return array<string, mixed> */
    private function serialize(Notification $notification): array
    {
        return [
            'id' => $notification->getId(),
            'kind' => $notification->getKind(),
            'subject_type' => $notification->getSubjectType(),
            'subject_id' => $notification->getSubjectId(),
            'project_id' => $notification->getProjectId(),
            'message' => $notification->getMessage(),
            'created_at' => $notification->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'read_at' => $notification->getReadAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
