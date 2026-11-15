<?php

namespace App\Controller;

use App\Service\Module\ModuleGate;
use App\Entity\User;
use App\Entity\RecordRevision;
use App\Repository\RecordRevisionRepository;
use App\Service\TrackedRecords;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Who changed a business record and which fields changed. Values are deliberately omitted (they can include
 * restricted data such as tax identifiers); administrators see full snapshots and can restore through /api/records.
 */
#[Route('/api/history', name: 'api_history_')]
#[IsGranted('ROLE_USER')]
final class RecordHistoryController extends AbstractController
{
    /** Record types and the switchable module they belong to. */
    private const MODULE_FOR_TYPE = [
        'client' => 'clients', 'client_contact' => 'clients',
        'deal' => 'sales_pipeline', 'project' => 'projects', 'project_milestone' => 'projects', 'task' => 'projects',
        'product' => 'sales', 'supplier' => 'inventory', 'warehouse' => 'inventory',
        'org_unit' => 'organization_structure', 'org_position' => 'organization_structure', 'employment' => 'organization_structure',
    ];

    public function __construct(
        private readonly RecordRevisionRepository $revisionRepository,
        private readonly TrackedRecords $trackedRecords,
        private readonly ModuleGate $modules,
    ) {
    }

    #[Route('/{type}/{id<\d+>}', name: 'show', methods: ['GET'])]
    public function show(string $type, int $id): JsonResponse
    {
        if ($this->trackedRecords->classFor($type) === null) {
            return $this->json(['error' => 'Unknown record type.'], JsonResponse::HTTP_NOT_FOUND);
        }
        $module = self::MODULE_FOR_TYPE[$type] ?? null;
        $user = $this->getUser();
        if ($module !== null && !$this->modules->isAvailableTo($module, $user instanceof User ? $user : null)) {
            return $this->json(['error' => 'Unknown record type.'], JsonResponse::HTTP_NOT_FOUND);
        }

        return $this->json(array_map(static fn (RecordRevision $revision): array => [
            'id' => $revision->getId(),
            'action' => $revision->getAction(),
            'actor' => $revision->getActor(),
            'changed_fields' => array_values(array_filter(array_keys($revision->getChanges() ?? []), static fn (string $field): bool => $field !== 'updatedAt')),
            'created_at' => $revision->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ], $this->revisionRepository->search($type, $id, null, 50)));
    }
}
