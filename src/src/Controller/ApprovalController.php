<?php

namespace App\Controller;

use App\Dto\ApprovalDecisionInput;
use App\Dto\ApprovalParticipantInput;
use App\Dto\ApprovalSubmitInput;
use App\Entity\ApprovalRoute;
use App\Entity\ApprovalStep;
use App\Entity\Document;
use App\Entity\User;
use App\Repository\ApprovalActionRepository;
use App\Repository\ApprovalRouteRepository;
use App\Repository\ApprovalStepRepository;
use App\Repository\DocumentRepository;
use App\Service\Dms\ApprovalService;
use App\Service\Dms\ApprovalViews;
use App\Service\Dms\DocumentAccess;
use App\Service\Dms\DocumentAccessDenied;
use App\Service\Dms\NotFound;
use App\Service\Org\OrgViews;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Document approval. Signed-in users only; who may act is decided per record by ApprovalService (assignee or their
 * deputy, author or clerk) and DocumentAccess. Steps and documents the caller cannot see answer 404.
 */
#[Route('/api/dms', name: 'api_dms_approvals_')]
#[IsGranted('ROLE_USER')]
final class ApprovalController extends AbstractController
{
    public function __construct(
        private readonly ApprovalService $approvals,
        private readonly ApprovalViews $views,
        private readonly OrgViews $orgViews,
        private readonly DocumentAccess $access,
        private readonly DocumentRepository $documentRepository,
        private readonly ApprovalRouteRepository $routeRepository,
        private readonly ApprovalStepRepository $stepRepository,
        private readonly ApprovalActionRepository $actionRepository,
        #[Autowire(service: 'monolog.logger.audit')] private readonly LoggerInterface $auditLogger,
    ) {
    }

    /** Open steps for the caller and for anyone they deputize for, earliest due first. */
    #[Route('/approvals/inbox', name: 'inbox', methods: ['GET'])]
    public function inbox(): JsonResponse
    {
        $viewer = $this->viewer();
        $chart = $this->access->chartToday();
        $today = new \DateTimeImmutable('today');

        return $this->json(array_map(
            fn (ApprovalStep $step): array => $this->views->inboxRow($step, $viewer, $chart, $today),
            $this->stepRepository->findOpenFor($this->access->actingForIds($viewer)),
        ));
    }

    #[Route('/approvals/counts', name: 'counts', methods: ['GET'])]
    public function counts(): JsonResponse
    {
        $viewer = $this->viewer();
        $today = new \DateTimeImmutable('today');
        $open = $this->stepRepository->findOpenFor($this->access->actingForIds($viewer));

        return $this->json([
            'awaiting' => count($open),
            'overdue' => count(array_filter($open, static fn (ApprovalStep $step): bool => $step->isOverdue($today))),
            'consultations' => count(array_filter($open, static fn (ApprovalStep $step): bool => $step->isConsultation())),
        ]);
    }

    #[Route('/approvals/steps/{id<\d+>}/decision', name: 'decide', methods: ['POST'])]
    public function decide(int $id, #[MapRequestPayload] ApprovalDecisionInput $input): JsonResponse
    {
        return $this->guard(function () use ($id, $input): JsonResponse {
            $step = $this->visibleStep($id);
            $route = $this->approvals->decide($step, $this->viewer(), (string) $input->decision, $input->comment);
            $this->auditLogger->info('Approval decision recorded.', ['document_id' => $route->getDocument()->getId(), 'step_id' => $id, 'decision' => $input->decision, 'actor' => $this->viewer()->getUserIdentifier()]);

            return $this->json($this->history($route->getDocument()));
        });
    }

    #[Route('/approvals/steps/{id<\d+>}/forward', name: 'forward', methods: ['POST'])]
    public function forwardForReview(int $id, #[MapRequestPayload] ApprovalParticipantInput $input): JsonResponse
    {
        return $this->guard(function () use ($id, $input): JsonResponse {
            $step = $this->visibleStep($id);
            $this->approvals->forward($step, $this->viewer(), (int) $input->assignee_id, $input->comment, $this->date($input->due_on));

            return $this->json($this->history($step->getStage()->getRoute()->getDocument()), Response::HTTP_CREATED);
        });
    }

    /** Subordinates of the step's assignee: the people its holder may ask for an advisory review. */
    #[Route('/approvals/steps/{id<\d+>}/forward-candidates', name: 'forward_candidates', methods: ['GET'])]
    public function forwardCandidates(int $id): JsonResponse
    {
        return $this->guard(function () use ($id): JsonResponse {
            $step = $this->visibleStep($id);
            $chart = $this->access->chartToday();
            $author = $step->getStage()->getRoute()->getDocument()->getAuthor();
            $people = array_values(array_filter($chart->subordinates($step->getAssignee()), static fn (User $user): bool => $user->getId() !== $author->getId()));

            return $this->json($this->orgViews->people($people, $chart));
        });
    }

    /** Every approval round of the document, newest first. */
    #[Route('/documents/{id<\d+>}/approval', name: 'history', methods: ['GET'])]
    public function documentHistory(int $id): JsonResponse
    {
        return $this->guard(fn (): JsonResponse => $this->json($this->history($this->readable($id))));
    }

    #[Route('/documents/{id<\d+>}/approval/candidates', name: 'candidates', methods: ['GET'])]
    public function candidates(int $id): JsonResponse
    {
        return $this->guard(function () use ($id): JsonResponse {
            $chart = $this->access->chartToday();
            $result = $this->approvals->candidates($this->readable($id), $this->viewer());

            return $this->json([
                'superiors' => array_map(fn (array $row): array => ['level' => $row['level']] + $this->orgViews->person($row['user'], $chart), $result['superiors']),
                'colleagues' => $this->orgViews->people($result['colleagues'], $chart),
                'template' => array_map(fn (array $entry): array => [
                    'kind' => $entry['kind'], 'level' => $entry['level'], 'due_on' => $entry['due_on'],
                    'assignee' => $entry['user'] !== null ? $this->orgViews->person($entry['user'], $chart) : null,
                ], $result['template']),
            ]);
        });
    }

    #[Route('/documents/{id<\d+>}/approval/submit', name: 'submit', methods: ['POST'])]
    public function submit(int $id, #[MapRequestPayload] ApprovalSubmitInput $input): JsonResponse
    {
        return $this->guard(function () use ($id, $input): JsonResponse {
            $document = $this->readable($id);
            $route = $this->approvals->submit($document, $this->viewer(), $input->stages, $input->comment);
            $this->auditLogger->info('Document sent for approval.', ['document_id' => $id, 'round' => $route->getRound(), 'actor' => $this->viewer()->getUserIdentifier()]);

            return $this->json($this->history($document), Response::HTTP_CREATED);
        });
    }

    #[Route('/documents/{id<\d+>}/approval/withdraw', name: 'withdraw', methods: ['POST'])]
    public function withdraw(int $id, Request $request): JsonResponse
    {
        return $this->guard(function () use ($id, $request): JsonResponse {
            $document = $this->readable($id);
            $body = json_decode($request->getContent() ?: '{}', true);
            $comment = is_array($body) && is_string($body['comment'] ?? null) ? mb_substr($body['comment'], 0, 4000) : null;
            $this->approvals->withdraw($document, $this->viewer(), $comment);
            $this->auditLogger->info('Approval withdrawn.', ['document_id' => $id, 'actor' => $this->viewer()->getUserIdentifier()]);

            return $this->json($this->history($document));
        });
    }

    #[Route('/documents/{id<\d+>}/approval/reviewers', name: 'reviewers', methods: ['POST'])]
    public function addReviewer(int $id, #[MapRequestPayload] ApprovalParticipantInput $input): JsonResponse
    {
        return $this->guard(function () use ($id, $input): JsonResponse {
            $document = $this->readable($id);
            $this->approvals->addReviewer($document, $this->viewer(), (int) $input->assignee_id, $this->date($input->due_on));

            return $this->json($this->history($document), Response::HTTP_CREATED);
        });
    }

    /** @return array<string, mixed> */
    private function history(Document $document): array
    {
        $routes = $this->routeRepository->findForDocument($document);
        $actions = $this->actionRepository->findForRoutes($routes);
        $viewer = $this->viewer();
        $chart = $this->access->chartToday();

        return [
            'document_id' => $document->getId(),
            'document_status' => $document->getStatus(),
            'permissions' => $this->access->permissions($viewer, $document),
            'rounds' => array_map(fn (ApprovalRoute $route): array => $this->views->route($route, $actions[(int) $route->getId()] ?? [], $viewer, $chart), $routes),
        ];
    }

    private function visibleStep(int $id): ApprovalStep
    {
        $step = $this->stepRepository->find($id);
        if (!$step instanceof ApprovalStep || !$this->access->canRead($this->viewer(), $step->getStage()->getRoute()->getDocument())) {
            throw new NotFound('Approval step not found.');
        }

        return $step;
    }

    private function readable(int $id): Document
    {
        $document = $this->documentRepository->find($id);
        if (!$document instanceof Document || !$this->access->canRead($this->viewer(), $document)) {
            throw new NotFound('Document not found.');
        }

        return $document;
    }

    private function date(?string $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('Dates must use YYYY-MM-DD.');
        }

        return $date;
    }

    /** @param callable(): Response $action */
    private function guard(callable $action): Response
    {
        try {
            return $action();
        } catch (NotFound $exception) {
            return $this->json(['error' => $exception->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (DocumentAccessDenied $exception) {
            return $this->json(['error' => $exception->getMessage()], Response::HTTP_FORBIDDEN);
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['error' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (\DomainException $exception) {
            return $this->json(['error' => $exception->getMessage()], Response::HTTP_CONFLICT);
        }
    }

    private function viewer(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
