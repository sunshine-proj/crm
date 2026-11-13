<?php

namespace App\Service\Dms;

use App\Repository\AcknowledgementRecipientRepository;
use App\Repository\AcknowledgementRequestRepository;
use App\Repository\ApprovalStepRepository;
use App\Repository\DocumentRepository;

/**
 * Registry reports for clerks:
 * - documents by type, owner unit and status (current state);
 * - approval time per step kind and per approver: from the step opening to its decision, for steps decided in the
 *   period (hours, mean and median);
 * - open approval steps past their due date, per approver (current state);
 * - acknowledgement completion per recorded unit, for requests sent in the period.
 */
final class DmsReports
{
    public function __construct(
        private readonly DocumentRepository $documentRepository,
        private readonly ApprovalStepRepository $stepRepository,
        private readonly AcknowledgementRequestRepository $requestRepository,
        private readonly AcknowledgementRecipientRepository $recipientRepository,
    ) {
    }

    /** @return array<string, mixed> */
    public function build(\DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeImmutable $today): array
    {
        $documents = $this->documentRepository->countByTypeUnitAndStatus();
        $byStatus = [];
        foreach ($documents as $row) {
            $byStatus[$row['status']] = ($byStatus[$row['status']] ?? 0) + $row['count'];
        }
        ksort($byStatus);

        $byKind = [];
        $byApprover = [];
        foreach ($this->stepRepository->findDecidedBetween($from->setTime(0, 0), $to->setTime(0, 0)->modify('+1 day')) as $step) {
            $hours = ($step->getDecidedAt()->getTimestamp() - $step->getOpenedAt()->getTimestamp()) / 3600;
            $byKind[$step->getKind()][] = $hours;
            $byApprover[$step->getAssigneeEmail()][] = $hours;
        }
        ksort($byKind);
        ksort($byApprover);

        $overdue = [];
        foreach ($this->stepRepository->findOpenWithDueDate() as $step) {
            if ($step->isOverdue($today)) {
                $email = $step->getAssigneeEmail();
                $overdue[$email] ??= ['approver' => $email, 'overdue' => 0, 'oldest_due_on' => null, 'max_days_overdue' => 0];
                ++$overdue[$email]['overdue'];
                $due = $step->getDueOn()->format('Y-m-d');
                if ($overdue[$email]['oldest_due_on'] === null || $due < $overdue[$email]['oldest_due_on']) {
                    $overdue[$email]['oldest_due_on'] = $due;
                    $overdue[$email]['max_days_overdue'] = (int) $step->getDueOn()->diff($today)->format('%a');
                }
            }
        }
        usort($overdue, static fn (array $a, array $b): int => [$b['overdue'], $a['approver']] <=> [$a['overdue'], $b['approver']]);

        $requests = $this->requestRepository->findCreatedBetween($from->setTime(0, 0), $to->setTime(0, 0)->modify('+1 day'));
        $units = [];
        foreach ($this->recipientRepository->findForRequests($requests) as $recipients) {
            foreach ($recipients as $recipient) {
                $units[$recipient->getUnitName() ?? 'No unit recorded'][] = $recipient;
            }
        }
        ksort($units);

        return [
            'period' => ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')],
            'documents' => ['by_status' => $byStatus, 'rows' => $documents, 'total' => array_sum($byStatus)],
            'approval_time' => [
                'by_kind' => array_map(fn (string $kind): array => ['kind' => $kind] + $this->stats($byKind[$kind]), array_keys($byKind)),
                'by_approver' => array_map(fn (string $email): array => ['approver' => $email] + $this->stats($byApprover[$email]), array_keys($byApprover)),
            ],
            'overdue_approvals' => array_values($overdue),
            'acknowledgement' => [
                'requests' => count($requests),
                'by_unit' => array_map(static fn (string $unit): array => ['unit' => $unit] + AcknowledgementViews::counts($units[$unit], $today), array_keys($units)),
            ],
        ];
    }

    /**
     * @param list<float> $hours
     * @return array{decided: int, mean_hours: float, median_hours: float, max_hours: float}
     */
    private function stats(array $hours): array
    {
        sort($hours);
        $count = count($hours);
        $median = $count % 2 === 1 ? $hours[intdiv($count, 2)] : ($hours[$count / 2 - 1] + $hours[$count / 2]) / 2;

        return ['decided' => $count, 'mean_hours' => round(array_sum($hours) / $count, 1), 'median_hours' => round($median, 1), 'max_hours' => round(max($hours), 1)];
    }
}
