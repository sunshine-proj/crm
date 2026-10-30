<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class TaskInput
{
    public function __construct(
        #[Assert\NotBlank(allowNull: true)]
        #[Assert\Length(max: 255)]
        public readonly ?string $title = null,
        #[Assert\NotBlank(allowNull: true)]
        public readonly ?string $description = null,
        #[Assert\Positive]
        public readonly ?int $project_id = null,
        #[Assert\Positive]
        public readonly ?int $assignee_id = null,
        public readonly ?\DateTimeImmutable $start_datetime = null,
        public readonly ?\DateTimeImmutable $end_datetime = null,
        #[Assert\Choice(choices: ['todo', 'in_progress', 'blocked', 'done'])]
        public readonly ?string $status = null,
        #[Assert\Choice(choices: ['low', 'normal', 'high', 'urgent'])]
        public readonly ?string $priority = null,
        #[Assert\Date]
        public readonly ?string $due_date = null,
    ) {
    }
}