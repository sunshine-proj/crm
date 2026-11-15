<?php

namespace App\Controller;

use App\Dto\TimeReport;
use App\Entity\User;
use App\Service\Time\TimeEntryFilter;
use App\Service\Time\TimeReportService;
use App\Service\Time\TimeVisibility;
use App\Service\Time\WorkloadService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Time totals per employee, project and task, and weekly workload. Every user may call these routes; the data is
 * limited by TimeVisibility (administrators and finance see everyone, project managers their projects, others
 * their own time). Labor cost is included only for ROLE_FINANCE.
 */
#[Route('/api/operations/time-report', name: 'api_operations_time_report')]
#[IsGranted('ROLE_USER')]
final class TimeReportController extends AbstractController
{
    public function __construct(
        private readonly TimeVisibility $timeVisibility,
        private readonly TimeReportService $reports,
        private readonly WorkloadService $workload,
        #[Autowire(service: 'monolog.logger.audit')] private readonly LoggerInterface $auditLogger,
    ) {
    }

    #[Route('', name: '', methods: ['GET'])]
    public function report(Request $request): JsonResponse
    {
        $built = $this->build($request);
        if ($built instanceof JsonResponse) return $built;

        return $this->json($this->reports->toArray($built));
    }

    #[Route('/export', name: '_export', methods: ['GET'])]
    public function export(Request $request): Response
    {
        $built = $this->build($request);
        if ($built instanceof JsonResponse) return $built;
        $user = $this->getUser();
        $this->auditLogger->info('Time report exported.', ['actor' => $user?->getUserIdentifier(), 'scope' => $built->scope, 'group_by' => $built->dimensions, 'rows' => count($built->rows)]);
        $response = new Response($this->reports->toCsv($built), Response::HTTP_OK, ['Content-Type' => 'text/csv; charset=UTF-8']);
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, 'time-report.csv'));

        return $response;
    }

    #[Route('/workload', name: '_workload', methods: ['GET'])]
    public function workload(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) return $this->json(['error' => 'Authenticated user is unavailable.'], JsonResponse::HTTP_UNAUTHORIZED);
        try {
            $filter = TimeEntryFilter::fromQuery($request->query);

            return $this->json($this->workload->weekly($this->timeVisibility->scopeFor($user), $filter->from, $filter->to, new \DateTimeImmutable('today')));
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['error' => $exception->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
        }
    }

    private function build(Request $request): TimeReport|JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) return $this->json(['error' => 'Authenticated user is unavailable.'], JsonResponse::HTTP_UNAUTHORIZED);
        try {
            $filter = TimeEntryFilter::fromQuery($request->query);
            $groupBy = $request->query->get('group_by');
            $dimensions = $this->reports->parseDimensions(is_string($groupBy) ? $groupBy : null);
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['error' => $exception->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
        }

        return $this->reports->report($this->timeVisibility->scopeFor($user), $filter, $dimensions, $this->isGranted('ROLE_FINANCE'));
    }
}
