<?php

namespace App\Controller;

use App\Dto\DashboardSummary;
use App\Entity\ProjectStatus;
use App\Repository\ClientRepository;
use App\Repository\ProjectRepository;
use App\Repository\TaskRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class DashboardController extends AbstractController
{
    public function __construct(
        private readonly ClientRepository $clientRepository,
        private readonly ProjectRepository $projectRepository,
        private readonly TaskRepository $taskRepository,
    ) {
    }

    #[Route('/api/dashboard/summary', name: 'api_dashboard_summary', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function summary(): JsonResponse
    {
        return $this->json(new DashboardSummary(
            client_count: $this->clientRepository->count([]),
            active_project_count: $this->projectRepository->countWithStatus(ProjectStatus::Active),
            open_task_count: $this->taskRepository->countOpen(),
        ));
    }
}