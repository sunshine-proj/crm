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

    /**
     * Maps one decoded JSON object (a timesheet batch element); null when a field has the wrong JSON type.
     *
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        foreach (['project_id', 'task_id', 'duration_minutes'] as $field) {
            if (isset($data[$field]) && !is_int($data[$field])) {
                return null;
            }
        }
        foreach (['work_date', 'description'] as $field) {
            if (isset($data[$field]) && !is_string($data[$field])) {
                return null;
            }
        }
        if (isset($data['billable']) && !is_bool($data['billable'])) {
            return null;
        }

        return new self($data['project_id'] ?? null, $data['task_id'] ?? null, $data['work_date'] ?? null, $data['duration_minutes'] ?? null, $data['billable'] ?? false, $data['description'] ?? null);
    }
}
