<?php

namespace App\Controller;

use App\Dto\DocumentRecordsInput;
use App\Entity\Document;
use App\Entity\DocumentDisposal;
use App\Entity\User;
use App\Repository\DocumentDisposalRepository;
use App\Repository\DocumentRepository;
use App\Service\Dms\DisposalService;
use App\Service\Dms\DmsReports;
use App\Service\Dms\DocumentAccessDenied;
use App\Service\Dms\NotFound;
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
 * Records management for the registry: disposal (clerks propose, a different administrator decides) and reports.
 * Everything here needs ROLE_DMS_ADMIN; deciding a disposal needs ROLE_ADMIN.
 */
#[Route('/api/dms', name: 'api_dms_records_')]
#[IsGranted('ROLE_DMS_ADMIN')]
final class RecordsController extends AbstractController
{
    public function __construct(
        private readonly DisposalService $disposals,
        private readonly DmsReports $reports,
        private readonly DocumentRepository $documentRepository,
        private readonly DocumentDisposalRepository $disposalRepository,
        #[Autowire(service: 'monolog.logger.audit')] private readonly LoggerInterface $auditLogger,
    ) {
    }

    /** Archived documents past their retention period, not held and not yet proposed. */
    #[Route('/disposals/eligible', name: 'disposals_eligible', methods: ['GET'])]
    public function eligible(): JsonResponse
    {
        return $this->json(array_map(static fn (Document $document): array => [
            'id' => $document->getId(), 'title' => $document->getTitle(), 'registration_number' => $document->getRegistrationNumber(),
            'type' => $document->getType()->getName(), 'archived_at' => $document->getArchivedAt()?->format(\DateTimeInterface::ATOM),
            'retention_years' => $document->getType()->getRetentionYears(), 'retain_until' => $document->retainUntil()?->format('Y-m-d'),
        ], $this->disposals->eligible(new \DateTimeImmutable('today'))));
    }

    #[Route('/disposals', name: 'disposals_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $status = $request->query->get('status');
        if ($status !== null && $status !== '' && !in_array($status, [DocumentDisposal::PROPOSED, DocumentDisposal::APPROVED, DocumentDisposal::REJECTED], true)) {
            return $this->json(['error' => 'Status must be proposed, approved or rejected.'], Response::HTTP_BAD_REQUEST);
        }
        $viewer = $this->viewer()->getUserIdentifier();
        $isAdmin = $this->isGranted('ROLE_ADMIN');

        return $this->json(array_map(fn (DocumentDisposal $disposal): array => $this->view($disposal) + [
            'can_decide' => $isAdmin && $disposal->isOpen() && strcasecmp($disposal->getProposedBy(), $viewer) !== 0,
        ], $this->disposalRepository->findRecent($status ?: null)));
    }

    #[Route('/documents/{id<\d+>}/disposal', name: 'disposals_propose', methods: ['POST'])]
    public function propose(int $id, #[MapRequestPayload] DocumentRecordsInput $input): JsonResponse
    {
        return $this->guard(function () use ($id, $input): JsonResponse {
            $document = $this->documentRepository->find($id) ?? throw new NotFound('Document not found.');
            $disposal = $this->disposals->propose($document, $this->viewer(), $input->reason);
            $this->auditLogger->notice('Disposal proposed.', ['document_id' => $id, 'disposal_id' => $disposal->getId(), 'actor' => $this->viewer()->getUserIdentifier()]);

            return $this->json($this->view($disposal), Response::HTTP_CREATED);
        });
    }

    #[Route('/disposals/{id<\d+>}/approve', name: 'disposals_approve', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function approve(int $id, #[MapRequestPayload] DocumentRecordsInput $input): JsonResponse
    {
        return $this->guard(function () use ($id, $input): JsonResponse {
            $disposal = $this->disposal($id);
            $this->disposals->approve($disposal, $this->viewer(), $input->comment);

            return $this->json($this->view($disposal));
        });
    }

    #[Route('/disposals/{id<\d+>}/reject', name: 'disposals_reject', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function reject(int $id, #[MapRequestPayload] DocumentRecordsInput $input): JsonResponse
    {
        return $this->guard(function () use ($id, $input): JsonResponse {
            $disposal = $this->disposal($id);
            $this->disposals->reject($disposal, $this->viewer(), $input->comment);
            $this->auditLogger->notice('Disposal rejected.', ['disposal_id' => $id, 'actor' => $this->viewer()->getUserIdentifier()]);

            return $this->json($this->view($disposal));
        });
    }

    /** Registry reports; `from` / `to` (YYYY-MM-DD, default: the last 90 days) bound approval times and acknowledgement requests. */
    #[Route('/reports', name: 'reports', methods: ['GET'])]
    public function reports(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            $today = new \DateTimeImmutable('today');
            $to = $this->date((string) $request->query->get('to', ''), 'to') ?? $today;
            $from = $this->date((string) $request->query->get('from', ''), 'from') ?? $to->modify('-90 days');
            if ($from > $to) {
                throw new \InvalidArgumentException('from must not be after to.');
            }
            if ($from < $to->modify('-5 years')) {
                throw new \InvalidArgumentException('The period can be at most five years.');
            }

            return $this->json($this->reports->build($from, $to, $today));
        });
    }

    /** @return array<string, mixed> */
    private function view(DocumentDisposal $disposal): array
    {
        $document = $disposal->getDocument();

        return [
            'id' => $disposal->getId(),
            'document' => ['id' => $document->getId(), 'title' => $document->getTitle(), 'registration_number' => $document->getRegistrationNumber(), 'type' => $document->getType()->getName(), 'status' => $document->getStatus()],
            'status' => $disposal->getStatus(),
            'proposed_by' => $disposal->getProposedBy(),
            'proposed_at' => $disposal->getProposedAt()->format(\DateTimeInterface::ATOM),
            'reason' => $disposal->getReason(),
            'retain_until' => $disposal->getRetainUntil()->format('Y-m-d'),
            'decided_by' => $disposal->getDecidedBy(),
            'decided_at' => $disposal->getDecidedAt()?->format(\DateTimeInterface::ATOM),
            'decision_comment' => $disposal->getDecisionComment(),
            'manifest' => $disposal->getManifest(),
        ];
    }

    private function disposal(int $id): DocumentDisposal
    {
        return $this->disposalRepository->find($id) ?? throw new NotFound('Disposal not found.');
    }

    private function date(string $value, string $name): ?\DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException(sprintf('%s must be a date in YYYY-MM-DD form.', $name));
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
