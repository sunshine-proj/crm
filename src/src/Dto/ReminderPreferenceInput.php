<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class ReminderPreferenceInput
{
    public function __construct(
        public readonly bool $enabled = true,
        #[Assert\Range(min: 0, max: 60)]
        public readonly int $task_lead_days = 1,
        #[Assert\Range(min: 0, max: 60)]
        public readonly int $project_lead_days = 3,
        #[Assert\Range(min: 0, max: 60)]
        public readonly int $escalate_after_days = 3,
    ) {
    }
}
