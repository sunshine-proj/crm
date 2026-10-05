<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class TimeEntryInput
{
    public function __construct(
        #[Assert\Positive]
        public readonly ?int $project_id = null,
        #[Assert\Positive]
        public readonly ?int $task_id = null,
        #[Assert\Date]
        public readonly ?string $work_date = null,
        #[Assert\Positive]
        #[Assert\LessThanOrEqual(1440)]
        public readonly ?int $duration_minutes = null,
        public readonly bool $billable = false,
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public readonly ?string $description = null,
    ) {
    }
}