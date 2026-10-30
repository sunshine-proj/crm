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
        #[Assert\Positive]
        public readonly ?int $manager_id = null,
        #[Assert\Choice(choices: ['planned', 'active', 'on_hold', 'completed', 'cancelled'])]
        public readonly ?string $status = null,
        #[Assert\Date]
        public readonly ?string $start_date = null,
        #[Assert\Date]
        public readonly ?string $end_date = null,
        public readonly ?string $budget_amount = null,
        #[Assert\Choice(choices: ['EUR', 'GBP', 'USD'])]
        public readonly ?string $budget_currency = null,
        public readonly ?string $hourly_rate = null,
        #[Assert\Choice(choices: ['EUR', 'GBP', 'USD'])]
        public readonly ?string $billing_currency = null,
    ) {
    }
}