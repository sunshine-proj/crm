<?php

namespace App\Dto;

/** Minutes split by approval state and billable flag, plus approved labor cost per currency (finance only). */
final class TimeTotals
{
    public int $total = 0;
    public int $approved = 0;
    public int $pending = 0;
    public int $rejected = 0;
    public int $billable = 0;
    /** @var array<string, int> approved cost in minor units by currency */
    public array $approvedCost = [];

    public function add(string $status, bool $billable, int $minutes, ?string $costCurrency = null, int $costMinor = 0): void
    {
        $this->total += $minutes;
        match ($status) {
            'approved' => $this->approved += $minutes,
            'rejected' => $this->rejected += $minutes,
            default => $this->pending += $minutes,
        };
        if ($billable) {
            $this->billable += $minutes;
        }
        if ($status === 'approved' && $costCurrency !== null) {
            $this->approvedCost[$costCurrency] = ($this->approvedCost[$costCurrency] ?? 0) + $costMinor;
        }
    }
}
