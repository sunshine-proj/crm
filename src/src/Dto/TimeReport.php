<?php

namespace App\Dto;

use App\Service\Time\TimeEntryFilter;

/** Aggregated time report as returned by the time-report endpoints. */
final class TimeReport
{
    /**
     * @param list<string> $dimensions
     * @param list<TimeReportRow> $rows
     */
    public function __construct(
        public readonly string $scope,
        public readonly array $dimensions,
        public readonly TimeEntryFilter $filter,
        public readonly array $rows,
        public readonly TimeTotals $totals,
        public readonly bool $withCost,
    ) {
    }
}
