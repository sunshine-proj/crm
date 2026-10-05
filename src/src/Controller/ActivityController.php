<?php

namespace App\Controller;

use App\Dto\ActivityInput;
use App\Entity\Activity;
use App\Entity\User;
use App\Repository\ActivityRepository;
use App\Repository\ClientRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/clients/{clientId<\d+>}/activities', name: 'api_client_activities_')]
final class ActivityController extends AbstractController
{
    public function __construct(
        private readonly ActivityRepository $activityRepository,
        private readonly ClientRepository $clientRepository,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire(service: 'monolog.logger.audit')]
        private readonly LoggerInterface $auditLogger,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function list(int $clientId): JsonResponse
    {
        $client = $this->clientRepository->find($clientId);
        if ($client === null) {
            return $this->json(['error' => 'Client not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        $items = $this->activityRepository->findByClient($clientId);

        return $this->json(array_map(fn (Activity $item): array => $this->serializeItem($item), $items));
    }

    #[Route('', name: 'create', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function create(int $clientId, #[MapRequestPayload] ActivityInput $input): JsonResponse
    {
        $client = $this->clientRepository->find($clientId);
        if ($client === null) {
            return $this->json(['error' => 'Client not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        $type = $input->type !== null ? trim($input->type) : 'update';
        $message = $input->message !== null ? trim($input->message) : null;
        if ($message === null || $message === '') {
            return $this->json(['error' => 'Activity message is required.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $now = new \DateTimeImmutable();
        $activity = (new Activity())
            ->setClient($client)
            ->setAuthor($this->getUser() instanceof User ? $this->getUser() : null)
            ->setType($type)
            ->setMessage($message)
            ->setCreatedAt($now)
            ->setUpdatedAt($now);

        $this->entityManager->persist($activity);
        $this->entityManager->flush();

        $this->auditLogger->info('Client activity created.', [
            'action' => 'client.activity.create',
            'actor' => $this->getAuditActor(),
            'client_id' => $clientId,
            'activity_id' => $activity->getId(),
            'activity_type' => $type,
        ]);

        return $this->json($this->serializeItem($activity), JsonResponse::HTTP_CREATED);
    }

    private function getAuditActor(): string
    {
        $user = $this->getUser();

        return $user instanceof User ? $user->getUserIdentifier() : 'anonymous';
    }

    /** @return array<string, int|string|null> */
    private function serializeItem(Activity $activity): array
    {
        return [
            'id' => $activity->getId(),
            'client_id' => $activity->getClient()?->getId(),
            'type' => $activity->getType(),
            'message' => $activity->getMessage(),
            'author_email' => $activity->getAuthor()?->getUserIdentifier(),
            'created_at' => $activity->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'updated_at' => $activity->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
