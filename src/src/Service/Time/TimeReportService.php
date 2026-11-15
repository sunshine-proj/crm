<?php

namespace App\Service\Time;

use App\Dto\TimeReport;
use App\Dto\TimeReportRow;
use App\Dto\TimeTotals;
use App\Repository\TimeEntryRepository;
use App\Service\InvoiceMoney;

/** Builds per-employee/project/task time totals from database aggregates, within the viewer's visibility scope. */
final class TimeReportService
{
    public const DIMENSIONS = ['employee', 'project', 'task'];

    public function __construct(
        private readonly TimeEntryRepository $timeEntryRepository,
        private readonly InvoiceMoney $money,
    ) {
    }

    /**
     * @return list<string> validated dimensions in the requested order
     * @throws \InvalidArgumentException
     */
    public function parseDimensions(?string $groupBy): array
    {
        if ($groupBy === null || trim($groupBy) === '') {
            return ['employee', 'project'];
        }
        $dimensions = array_map('trim', explode(',', $groupBy));
        if (count($dimensions) > 3 || array_diff($dimensions, self::DIMENSIONS) !== [] || count(array_unique($dimensions)) !== count($dimensions)) {
            throw new \InvalidArgumentException('group_by must list distinct values from employee, project and task.');
        }

        return $dimensions;
    }

    /** @param list<string> $dimensions */
    public function report(TimeScope $scope, TimeEntryFilter $filter, array $dimensions, bool $withCost): TimeReport
    {
        $rows = [];
        $totals = new TimeTotals();
        foreach ($this->timeEntryRepository->aggregate($scope, $filter, $dimensions, $withCost) as $part) {
            $keys = [];
            foreach (['employee_id', 'employee_email', 'project_id', 'project_title', 'task_id', 'task_title', 'task_estimated_minutes'] as $column) {
                if (array_key_exists($column, $part)) {
                    $keys[$column] = str_ends_with($column, '_id') || $column === 'task_estimated_minutes'
                        ? ($part[$column] === null ? null : (int) $part[$column])
                        : $part[$column];
                }
            }
            $key = json_encode($keys, JSON_THROW_ON_ERROR);
            $rows[$key] ??= new TimeReportRow($keys);
            $minutes = (int) $part['minutes'];
            $currency = $withCost ? ($part['cost_currency'] ?? null) : null;
            $cost = $withCost && isset($part['cost_minor']) ? (int) $part['cost_minor'] : 0;
            $billable = (bool) $part['billable'];
            $rows[$key]->totals->add((string) $part['approval_status'], $billable, $minutes, $currency, $cost);
            $totals->add((string) $part['approval_status'], $billable, $minutes, $currency, $cost);
        }
        $rows = array_values($rows);
        usort($rows, static fn (TimeReportRow $a, TimeReportRow $b): int => strcmp($a->sortKey($dimensions), $b->sortKey($dimensions)));

        return new TimeReport($scope->level(), $dimensions, $filter, $rows, $totals, $withCost);
    }

    /** @return array<string, mixed> JSON response body */
    public function toArray(TimeReport $report): array
    {
        return [
            'scope' => $report->scope,
            'group_by' => $report->dimensions,
            'filters' => [
                'from' => $report->filter->from?->format('Y-m-d'),
                'to' => $report->filter->to?->format('Y-m-d'),
                'project_id' => $report->filter->projectId,
                'task_id' => $report->filter->taskId,
                'user_id' => $report->filter->userId,
                'status' => $report->filter->status,
                'billable' => $report->filter->billable,
            ],
            'rows' => array_map(fn (TimeReportRow $row): array => $row->keys + $this->totalsToArray($row->totals, $report->withCost), $report->rows),
            'totals' => $this->totalsToArray($report->totals, $report->withCost),
        ];
    }

    /** CSV with one line per group; cells that a spreadsheet would read as a formula are prefixed with a quote. */
    public function toCsv(TimeReport $report): string
    {
        $currencies = [];
        if ($report->withCost) {
            foreach ($report->rows as $row) {
                $currencies += array_fill_keys(array_keys($row->totals->approvedCost), true);
            }
            ksort($currencies);
        }
        $header = [];
        foreach ($report->dimensions as $dimension) {
            $header = [...$header, ...match ($dimension) {
                'employee' => ['Employee'],
                'project' => ['Project'],
                default => ['Task', 'Task estimate (minutes)'],
            }];
        }
        $header = [...$header, 'Total minutes', 'Total hours', 'Approved minutes', 'Pending minutes', 'Rejected minutes', 'Billable minutes'];
        foreach (array_keys($currencies) as $currency) {
            $header[] = 'Approved cost '.$currency;
        }
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $header, escape: '');
        foreach ($report->rows as $row) {
            $line = [];
            foreach ($report->dimensions as $dimension) {
                $line = [...$line, ...match ($dimension) {
                    'employee' => [$row->keys['employee_email']],
                    'project' => [$row->keys['project_title']],
                    default => [$row->keys['task_title'] ?? 'No task', $row->keys['task_estimated_minutes'] ?? ''],
                }];
            }
            $totals = $row->totals;
            $line = [...$line, $totals->total, $this->hours($totals->total), $totals->approved, $totals->pending, $totals->rejected, $totals->billable];
            foreach (array_keys($currencies) as $currency) {
                $line[] = isset($totals->approvedCost[$currency]) ? $this->money->formatMinor($totals->approvedCost[$currency]) : '';
            }
            fputcsv($handle, array_map(self::safeCell(...), $line), escape: '');
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /** Decimal hours with two places, rounded half up, using integer arithmetic. */
    public function hours(int $minutes): string
    {
        $hundredths = intdiv($minutes * 100 + 30, 60);

        return sprintf('%d.%02d', intdiv($hundredths, 100), $hundredths % 100);
    }

    /** @return array<string, mixed> */
    private function totalsToArray(TimeTotals $totals, bool $withCost): array
    {
        $result = [
            'total_minutes' => $totals->total,
            'approved_minutes' => $totals->approved,
            'pending_minutes' => $totals->pending,
            'rejected_minutes' => $totals->rejected,
            'billable_minutes' => $totals->billable,
        ];
        if ($withCost) {
            ksort($totals->approvedCost);
            $result['approved_cost'] = array_map(
                fn (string $currency, int $amount): array => ['currency' => $currency, 'amount' => $this->money->formatMinor($amount)],
                array_keys($totals->approvedCost),
                array_values($totals->approvedCost),
            );
        }

        return $result;
    }

    private static function safeCell(int|string|null $value): int|string
    {
        if ($value === null) {
            return '';
        }
        if (is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }
}
