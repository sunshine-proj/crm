<?php

namespace App\Controller;

use App\Entity\RecordRevision;
use App\Entity\User;
use App\Repository\RecordRevisionRepository;
use App\Service\RecordRecovery;
use App\Service\TrackedRecords;
use Doctrine\DBAL\Exception\ConstraintViolationException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/records', name: 'api_records_')]
#[IsGranted('ROLE_ADMIN')]
final class RecordRevisionController extends AbstractController
{
    public function __construct(
        private readonly RecordRevisionRepository $revisionRepository,
        private readonly RecordRecovery $recovery,
        private readonly TrackedRecords $trackedRecords,
        #[Autowire(service: 'monolog.logger.audit')] private readonly LoggerInterface $auditLogger,
    ) {
    }

    #[Route('/revisions', name: 'revisions_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $type = $request->query->get('type');
        if ($type !== null && $this->trackedRecords->classFor((string) $type) === null) {
            return $this->json(['error' => 'Unknown record type.', 'types' => $this->trackedRecords->types()], JsonResponse::HTTP_BAD_REQUEST);
        }
        $action = $request->query->get('action');
        if ($action !== null && !in_array($action, [RecordRevision::ACTION_CREATE, RecordRevision::ACTION_UPDATE, RecordRevision::ACTION_DELETE], true)) {
            return $this->json(['error' => 'Action must be create, update, or delete.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        $entityId = $request->query->get('entity_id');
        if ($entityId !== null && (!ctype_digit((string) $entityId) || (int) $entityId < 1)) {
            return $this->json(['error' => 'entity_id must be a positive integer.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        $limit = $request->query->get('limit', '50');
        if (!ctype_digit((string) $limit) || (int) $limit < 1 || (int) $limit > 200) {
            return $this->json(['error' => 'limit must be between 1 and 200.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $revisions = $this->revisionRepository->search(
            $type !== null ? (string) $type : null,
            $entityId !== null ? (int) $entityId : null,
            $action !== null ? (string) $action : null,
            (int) $limit,
        );

        return $this->json(array_map($this->serialize(...), $revisions));
    }

    #[Route('/revisions/{id<\d+>}/restore', name: 'revisions_restore', methods: ['POST'])]
    public function restore(int $id): JsonResponse
    {
        $revision = $this->revisionRepository->find($id);
        if (!$revision instanceof RecordRevision) {
            return $this->json(['error' => 'Revision not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        try {
            $result = $this->recovery->restore($revision);
        } catch (\DomainException $exception) {
            return $this->json(['error' => $exception->getMessage()], JsonResponse::HTTP_CONFLICT);
        } catch (ConstraintViolationException) {
            return $this->json(['error' => 'The restored values conflict with existing data (for example a duplicate code).'], JsonResponse::HTTP_CONFLICT);
        }

        $actor = $this->getUser();
        $this->auditLogger->info('Record restored from revision.', [
            'revision_id' => $id,
            'record_type' => $result['type'],
            'record_id' => $revision->getEntityId(),
            'recreated' => $result['recreated'],
            'actor' => $actor instanceof User ? $actor->getUserIdentifier() : null,
        ]);

        return $this->json([
            'revision_id' => $id,
            'record_type' => $result['type'],
            'record_id' => $result['entity']->getId(),
            'recreated' => $result['recreated'],
        ]);
    }

    /** @return array<string, mixed> */
    private function serialize(RecordRevision $revision): array
    {
        return [
            'id' => $revision->getId(),
            'record_type' => $revision->getEntityType(),
            'record_id' => $revision->getEntityId(),
            'action' => $revision->getAction(),
            'snapshot' => $revision->getSnapshot(),
            'changes' => $revision->getChanges(),
            'actor' => $revision->getActor(),
            'created_at' => $revision->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
