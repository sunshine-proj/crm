<?php

namespace App\Dto;

final readonly class DashboardSummary
{
    public function __construct(
        public int $client_count,
        public int $active_project_count,
        public int $open_task_count,
    ) {
    }
}