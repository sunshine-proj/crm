<?php

namespace App\Dto;

/** One group of a time report: the grouped dimension values plus their totals. */
final class TimeReportRow
{
    /** @param array<string, int|string|null> $keys dimension columns (employee_id, project_title, ...) */
    public function __construct(
        public readonly array $keys,
        public readonly TimeTotals $totals = new TimeTotals(),
    ) {
    }

    /** @param list<string> $dimensions grouping order, which is also the sort order */
    public function sortKey(array $dimensions): string
    {
        $labels = [];
        foreach ($dimensions as $dimension) {
            $label = ['employee' => 'employee_email', 'project' => 'project_title', 'task' => 'task_title'][$dimension];
            if (array_key_exists($label, $this->keys)) {
                // Entries without a task sort after named tasks.
                $labels[] = $this->keys[$label] === null ? "\u{10FFFF}" : mb_strtolower((string) $this->keys[$label]);
            }
        }

        return implode("\0", $labels);
    }
}
