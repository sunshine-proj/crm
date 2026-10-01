<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class ProjectInput
{
    public function __construct(
        #[Assert\NotBlank(allowNull: true)]
        #[Assert\Length(max: 255)]
        public readonly ?string $title = null,
        #[Assert\NotBlank(allowNull: true)]
        public readonly ?string $description = null,
        #[Assert\Positive]
        public readonly ?int $client_id = null,
        #[Assert\Choice(choices: ['planned', 'active', 'on_hold', 'completed', 'cancelled'])]
        public readonly ?string $status = null,
        #[Assert\Date]
        public readonly ?string $start_date = null,
        #[Assert\Date]
        public readonly ?string $end_date = null,
    ) {
    }
}