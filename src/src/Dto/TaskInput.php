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
        public readonly ?\DateTimeImmutable $start_datetime = null,
        public readonly ?\DateTimeImmutable $end_datetime = null,
    ) {
    }
}