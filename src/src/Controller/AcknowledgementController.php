<?php

namespace App\Controller;

use App\Dto\AcknowledgementInput;
use App\Entity\AcknowledgementRecipient;
use App\Entity\AcknowledgementRequest;
use App\Entity\Document;
use App\Entity\OrgUnit;
use App\Entity\Position;
use App\Entity\User;
use App\Repository\AcknowledgementRecipientRepository;
use App\Repository\AcknowledgementRequestRepository;
use App\Repository\DocumentRepository;
use App\Repository\OrgUnitRepository;
use App\Repository\PositionRepository;
use App\Service\Dms\AcknowledgementService;
use App\Service\Dms\AcknowledgementSheetPdf;
use App\Service\Dms\AcknowledgementViews;
use App\Service\Dms\DocumentAccess;
use App\Service\Dms\DocumentAccessDenied;
use App\Service\Dms\NotFound;
use App\Service\Org\OrgViews;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Acknowledgement campaigns. Signed-in users only; record-level rules live in AcknowledgementService: unit heads
 * (own subtree) and registry clerks send, the sender and clerks control a request, managers of affected units see
 * and supervise their part, and recipients confirm personally. Requests and documents the caller may not see
 * answer 404.
 */
#[Route('/api/dms', name: 'api_dms_ack_')]
#[IsGranted('ROLE_USER')]
final class AcknowledgementController extends AbstractController
{
    public function __construct(
        private readonly AcknowledgementService $acknowledgements,
        private readonly AcknowledgementViews $views,
        private readonly AcknowledgementSheetPdf $sheetPdf,
        private readonly OrgViews $orgViews,
        private readonly DocumentAccess $access,
        private readonly DocumentRepository $documentRepository,
        private readonly AcknowledgementRequestRepository $requestRepository,
        private readonly AcknowledgementRecipientRepository $recipientRepository,
        private readonly OrgUnitRepository $unitRepository,
        private readonly PositionRepository $positionRepository,
        #[Autowire(service: 'monolog.logger.audit')] private readonly LoggerInterface $auditLogger,
    ) {
    }

    /** What the caller still has to acknowledge, earliest deadline first. */
    #[Route('/acknowledgements/inbox', name: 'inbox', methods: ['GET'])]
    public function inbox(): JsonResponse
    {
        $today = new \DateTimeImmutable('today');

        return $this->json(array_map(fn (AcknowledgementRecipient $row): array => $this->views->mine($row, $today), $this->recipientRepository->findOpenFor($this->viewer())));
    }

    #[Route('/acknowledgements/counts', name: 'counts', methods: ['GET'])]
    public function counts(): JsonResponse
    {
        $today = new \DateTimeImmutable('today');
        $open = $this->recipientRepository->findOpenFor($this->viewer());

        return $this->json([
            'to_acknowledge' => count($open),
            'overdue' => count(array_filter($open, static fn (AcknowledgementRecipient $row): bool => $row->isOverdue($today))),
        ]);
    }

    /** Everything the caller was ever asked to acknowledge, newest first. */
    #[Route('/acknowledgements/history', name: 'history', methods: ['GET'])]
    public function history(): JsonResponse
    {
        $today = new \DateTimeImmutable('today');

        return $this->json(array_map(fn (AcknowledgementRecipient $row): array => $this->views->mine($row, $today), $this->recipientRepository->findHistoryFor($this->viewer())));
    }

    /** Requests the caller sent or whose recipients include people in units they manage (clerks: all). `status=open|closed`. */
    #[Route('/acknowledgements', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $status = $request->query->get('status');
        if ($status !== null && $status !== '' && !in_array($status, [AcknowledgementRequest::OPEN, AcknowledgementRequest::CLOSED], true)) {
            return $this->error('Status must be open or closed.', Response::HTTP_BAD_REQUEST);
        }
        $viewer = $this->viewer();
        $managed = $this->access->managedUnitIds($viewer);
        $requests = $this->requestRepository->findMonitored($this->access->isRegistrar() ? null : $viewer, $managed, $status ?: null);
        $recipients = $this->recipientRepository->findForRequests($requests);
        $today = new \DateTimeImmutable('today');
        $rows = [];
        foreach ($requests as $item) {
            $all = $recipients[(int) $item->getId()] ?? [];
            $scope = $this->acknowledgements->monitorScope($item, $viewer, $all);
            if ($scope !== false) {
                $rows[] = $this->views->request($item, $this->within($all, $scope), $today) + ['is_partial' => $scope !== null];
            }
        }

        return $this->json($rows);
    }

    /** The status board: request, completion per unit (rolled up), and the people; `status` filters the people. */
    #[Route('/acknowledgements/{id<\d+>}', name: 'show', methods: ['GET'])]
    public function show(int $id, Request $request): JsonResponse
    {
        return $this->guard(function () use ($id, $request): JsonResponse {
            [$item, $recipients, $scope] = $this->monitored($id);
            $today = new \DateTimeImmutable('today');
            $viewer = $this->viewer();
            $filter = (string) $request->query->get('status', '');
            $listed = match ($filter) {
                '' => $recipients,
                'overdue' => array_filter($recipients, static fn (AcknowledgementRecipient $row): bool => $row->isOverdue($today)),
                'open' => array_filter($recipients, static fn (AcknowledgementRecipient $row): bool => $row->isOpen()),
                default => in_array($filter, AcknowledgementRecipient::STATUSES, true)
                    ? array_filter($recipients, static fn (AcknowledgementRecipient $row): bool => $row->getStatus() === $filter)
                    : throw new \InvalidArgumentException('Unknown status filter.'),
            };
            $controller = $this->acknowledgements->isController($item, $viewer);

            return $this->json($this->views->request($item, $recipients, $today) + [
                'is_partial' => $scope !== null,
                'units' => $this->views->unitTree($recipients, $scope, $today),
                'recipients' => array_map(fn (AcknowledgementRecipient $row): array => $this->views->recipient($row, $today, $viewer), array_values($listed)),
                'permissions' => [
                    'can_control' => $controller && $item->isOpen(),
                    'can_reissue' => $controller,
                    'can_remind' => $item->isOpen(),
                ],
            ]);
        });
    }

    #[Route('/acknowledgements/{id<\d+>}/export.csv', name: 'export', methods: ['GET'])]
    public function export(int $id): Response
    {
        return $this->guard(function () use ($id): Response {
            [$item, $recipients] = $this->monitored($id);
            $handle = fopen('php://temp', 'w+');
            fputcsv($handle, ['Employee', 'Position', 'Unit', 'Status', 'Overdue', 'Opened at', 'Acknowledged at', 'Late', 'Excuse reason', 'Excused by', 'Version', 'Acknowledged SHA-256', 'Added', 'Reminders'], escape: '');
            $today = new \DateTimeImmutable('today');
            foreach ($recipients as $row) {
                fputcsv($handle, array_map(self::csvCell(...), [
                    $row->getUserEmail(), $row->getPositionTitle() ?? '', $row->getUnitName() ?? '', $row->getStatus(), $row->isOverdue($today) ? 'yes' : '',
                    $row->getViewedAt()?->format('Y-m-d H:i') ?? '', $row->getAcknowledgedAt()?->format('Y-m-d H:i') ?? '', $row->isLate() ? 'yes' : '',
                    $row->getExcuseReason() ?? '', $row->getExcusedBy() ?? '', 'v'.$item->getVersionNumber(), $row->getAcknowledgedSha256() ?? '', $row->getSource(), (string) $row->getReminderCount(),
                ]), escape: '');
            }
            rewind($handle);
            $csv = "\xEF\xBB\xBF".stream_get_contents($handle);
            fclose($handle);
            $this->auditLogger->info('Acknowledgement status exported.', ['request_id' => $id, 'format' => 'csv', 'actor' => $this->viewer()->getUserIdentifier()]);

            return new Response($csv, Response::HTTP_OK, [
                'Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, sprintf('acknowledgement-%d.csv', $id)),
                'X-Content-Type-Options' => 'nosniff',
            ]);
        });
    }

    #[Route('/acknowledgements/{id<\d+>}/sheet.pdf', name: 'sheet', methods: ['GET'])]
    public function sheet(int $id): Response
    {
        return $this->guard(function () use ($id): Response {
            [$item, $recipients, $scope] = $this->monitored($id);
            $this->auditLogger->info('Acknowledgement status exported.', ['request_id' => $id, 'format' => 'pdf', 'actor' => $this->viewer()->getUserIdentifier()]);

            return new Response($this->sheetPdf->render($item, $recipients, $scope !== null), Response::HTTP_OK, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, sprintf('acknowledgement-sheet-%d.pdf', $id)),
                'X-Content-Type-Options' => 'nosniff',
            ]);
        });
    }

    /** Reminds `recipient_ids`, or everyone who still owes a confirmation within the caller's supervision. */
    #[Route('/acknowledgements/{id<\d+>}/remind', name: 'remind', methods: ['POST'])]
    public function remind(int $id, #[MapRequestPayload] AcknowledgementInput $input = new AcknowledgementInput()): JsonResponse
    {
        return $this->guard(function () use ($id, $input): JsonResponse {
            [$item] = $this->monitored($id);

            return $this->json($this->acknowledgements->remind($item, $this->viewer(), $input->recipient_ids));
        });
    }

    #[Route('/acknowledgements/{id<\d+>}/deadline', name: 'deadline', methods: ['POST'])]
    public function deadline(int $id, #[MapRequestPayload] AcknowledgementInput $input): JsonResponse
    {
        return $this->guard(function () use ($id, $input): JsonResponse {
            [$item] = $this->monitored($id);
            $this->acknowledgements->extend($item, $this->viewer(), $this->date($input->due_on));

            return $this->show($id, new Request());
        });
    }

    #[Route('/acknowledgements/{id<\d+>}/recipients', name: 'recipients', methods: ['POST'])]
    public function addRecipients(int $id, #[MapRequestPayload] AcknowledgementInput $input): JsonResponse
    {
        return $this->guard(function () use ($id, $input): JsonResponse {
            [$item] = $this->monitored($id);
            $added = $this->acknowledgements->addTargets($item, $this->viewer(), $input->targets);
            $this->auditLogger->info('Acknowledgement recipients added.', ['request_id' => $id, 'count' => $added, 'actor' => $this->viewer()->getUserIdentifier()]);

            return $this->json(['added' => $added] + json_decode((string) $this->show($id, new Request())->getContent(), true), Response::HTTP_CREATED);
        });
    }

    #[Route('/acknowledgements/{id<\d+>}/close', name: 'close', methods: ['POST'])]
    public function close(int $id): JsonResponse
    {
        return $this->guard(function () use ($id): JsonResponse {
            [$item] = $this->monitored($id);
            $this->acknowledgements->close($item, $this->viewer());
            $this->auditLogger->info('Acknowledgement request closed.', ['request_id' => $id, 'actor' => $this->viewer()->getUserIdentifier()]);

            return $this->show($id, new Request());
        });
    }

    /** New request for the newer version (or a replacing/amending document, `document_id`) with the same audience. */
    #[Route('/acknowledgements/{id<\d+>}/reissue', name: 'reissue', methods: ['POST'])]
    public function reissue(int $id, #[MapRequestPayload] AcknowledgementInput $input = new AcknowledgementInput()): JsonResponse
    {
        return $this->guard(function () use ($id, $input): JsonResponse {
            [$item] = $this->monitored($id);
            $new = $this->acknowledgements->reissue($item, $this->viewer(), $input->document_id, $this->date($input->due_on), $input->message, $input->require_open);
            $this->auditLogger->info('Acknowledgement re-issued.', ['request_id' => $id, 'new_request_id' => $new->getId(), 'actor' => $this->viewer()->getUserIdentifier()]);

            return $this->show((int) $new->getId(), new Request())->setStatusCode(Response::HTTP_CREATED);
        });
    }

    #[Route('/acknowledgements/recipients/{id<\d+>}/acknowledge', name: 'acknowledge', methods: ['POST'])]
    public function acknowledge(int $id): JsonResponse
    {
        return $this->guard(function () use ($id): JsonResponse {
            $row = $this->recipient($id);
            $this->acknowledgements->acknowledge($row, $this->viewer());
            $this->auditLogger->info('Document acknowledged.', ['document_id' => $row->getRequest()->getDocument()->getId(), 'request_id' => $row->getRequest()->getId(), 'sha256' => $row->getAcknowledgedSha256(), 'actor' => $this->viewer()->getUserIdentifier()]);

            return $this->json($this->views->mine($row, new \DateTimeImmutable('today')));
        });
    }

    #[Route('/acknowledgements/recipients/{id<\d+>}/excuse', name: 'excuse', methods: ['POST'])]
    public function excuse(int $id, #[MapRequestPayload] AcknowledgementInput $input): JsonResponse
    {
        return $this->guard(function () use ($id, $input): JsonResponse {
            $row = $this->recipient($id);
            $this->acknowledgements->excuse($row, $this->viewer(), $input->reason);

            return $this->json($this->views->recipient($row, new \DateTimeImmutable('today'), $this->viewer()));
        });
    }

    #[Route('/acknowledgements/recipients/{id<\d+>}/excuse', name: 'reinstate', methods: ['DELETE'])]
    public function reinstate(int $id): JsonResponse
    {
        return $this->guard(function () use ($id): JsonResponse {
            $row = $this->recipient($id);
            $this->acknowledgements->reinstate($row, $this->viewer());

            return $this->json($this->views->recipient($row, new \DateTimeImmutable('today'), $this->viewer()));
        });
    }

    /** The document's requests the caller may monitor, the caller's own rows, and whether they may send one. */
    #[Route('/documents/{id<\d+>}/acknowledgements', name: 'document', methods: ['GET'])]
    public function forDocument(int $id): JsonResponse
    {
        return $this->guard(function () use ($id): JsonResponse {
            $document = $this->readable($id);
            $viewer = $this->viewer();
            $today = new \DateTimeImmutable('today');
            $requests = $this->requestRepository->findForDocument($document);
            $recipients = $this->recipientRepository->findForRequests($requests);
            $monitored = [];
            foreach ($requests as $item) {
                $all = $recipients[(int) $item->getId()] ?? [];
                $scope = $this->acknowledgements->monitorScope($item, $viewer, $all);
                if ($scope !== false) {
                    $monitored[] = $this->views->request($item, $this->within($all, $scope), $today) + ['is_partial' => $scope !== null];
                }
            }

            return $this->json([
                'can_send' => $this->access->permissions($viewer, $document)['can_request_acknowledgement'],
                'mine' => array_map(fn (AcknowledgementRecipient $row): array => $this->views->mine($row, $today), $this->recipientRepository->findForDocumentAndUser($document, $viewer)),
                'requests' => $monitored,
            ]);
        });
    }

    /** Units, positions and people the caller may address: their management subtree, or everything for clerks. */
    #[Route('/documents/{id<\d+>}/acknowledgements/audience', name: 'audience', methods: ['GET'])]
    public function audience(int $id): JsonResponse
    {
        return $this->guard(function () use ($id): JsonResponse {
            $this->readable($id);
            $scope = $this->acknowledgements->scopeOf($this->viewer());
            if ($scope === []) {
                throw new DocumentAccessDenied('Only unit heads and registry clerks can ask for acknowledgement.');
            }
            $chart = $this->access->chartToday();
            $units = array_values(array_filter($this->unitRepository->findAllOrdered(), static fn (OrgUnit $unit): bool => !$unit->isArchived() && ($scope === null || in_array($unit->getId(), $scope, true))));
            $unitIds = array_map(static fn (OrgUnit $unit): int => (int) $unit->getId(), $units);
            $positions = array_values(array_filter($this->positionRepository->findAllOrdered(), static fn (Position $position): bool => !$position->isArchived() && in_array($position->getUnit()->getId(), $unitIds, true)));
            $people = [];
            foreach ($unitIds as $unitId) {
                foreach ($chart->members($unitId) as $member) {
                    $people[(int) $member->getId()] = $member;
                }
            }
            unset($people[(int) $this->viewer()->getId()]);
            usort($people, static fn (User $a, User $b): int => strcmp($a->getUserIdentifier(), $b->getUserIdentifier()));

            return $this->json([
                'organization_wide' => $scope === null,
                'units' => array_map(fn (OrgUnit $unit): array => $this->orgViews->unit($unit, $chart), $units),
                'positions' => array_map(static fn (Position $position): array => [
                    'id' => $position->getId(), 'title' => $position->getTitle(), 'is_head' => $position->isHead(),
                    'unit_id' => $position->getUnit()->getId(), 'unit_name' => $position->getUnit()->getName(), 'holder_count' => count($chart->holdersOf((int) $position->getId())),
                ], $positions),
                'people' => $this->orgViews->people(array_values($people), $chart),
            ]);
        });
    }

    #[Route('/documents/{id<\d+>}/acknowledgements', name: 'send', methods: ['POST'])]
    public function send(int $id, #[MapRequestPayload] AcknowledgementInput $input): JsonResponse
    {
        return $this->guard(function () use ($id, $input): JsonResponse {
            $document = $this->readable($id);
            $item = $this->acknowledgements->send($document, $this->viewer(), $input->targets, $input->message, $this->date($input->due_on), $input->require_open ?? true, $input->auto_add_joiners ?? true);
            $this->auditLogger->info('Document sent for acknowledgement.', ['document_id' => $id, 'request_id' => $item->getId(), 'actor' => $this->viewer()->getUserIdentifier()]);

            return $this->show((int) $item->getId(), new Request())->setStatusCode(Response::HTTP_CREATED);
        });
    }

    /**
     * @return array{0: AcknowledgementRequest, 1: list<AcknowledgementRecipient>, 2: list<int>|null}
     */
    private function monitored(int $id): array
    {
        $item = $this->requestRepository->find($id);
        if (!$item instanceof AcknowledgementRequest) {
            throw new NotFound('Acknowledgement request not found.');
        }
        $all = $this->recipientRepository->findForRequest($item);
        $scope = $this->acknowledgements->monitorScope($item, $this->viewer(), $all);
        if ($scope === false) {
            throw new NotFound('Acknowledgement request not found.');
        }

        return [$item, $this->within($all, $scope), $scope];
    }

    /**
     * @param list<AcknowledgementRecipient> $recipients
     * @param list<int>|null $scope
     * @return list<AcknowledgementRecipient>
     */
    private function within(array $recipients, ?array $scope): array
    {
        return $scope === null ? $recipients : array_values(array_filter($recipients, static fn (AcknowledgementRecipient $row): bool => in_array($row->getUnit()?->getId(), $scope, true)));
    }

    /** A recipient row the caller may act on: their own, or one on a request they monitor. */
    private function recipient(int $id): AcknowledgementRecipient
    {
        $row = $this->recipientRepository->find($id);
        $viewer = $this->viewer();
        if (!$row instanceof AcknowledgementRecipient) {
            throw new NotFound('Acknowledgement not found.');
        }
        if ($row->getUser()->getId() !== $viewer->getId()) {
            $scope = $this->acknowledgements->monitorScope($row->getRequest(), $viewer, [$row]);
            if ($scope === false) {
                throw new NotFound('Acknowledgement not found.');
            }
        }

        return $row;
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

    /** Spreadsheet formula injection guard: text starting with a formula character is prefixed with an apostrophe. */
    private static function csvCell(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }

    /** @param callable(): Response $action */
    private function guard(callable $action): Response
    {
        try {
            return $action();
        } catch (NotFound $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (DocumentAccessDenied $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_FORBIDDEN);
        } catch (\InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_BAD_REQUEST);
        } catch (\DomainException $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_CONFLICT);
        }
    }

    private function error(string $message, int $status): JsonResponse
    {
        return $this->json(['error' => $message], $status);
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
