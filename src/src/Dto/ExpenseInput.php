<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class ExpenseInput
{
    public function __construct(
        #[Assert\Positive]
        public readonly ?int $project_id = null,
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public readonly ?string $description = null,
        public readonly ?string $amount = null,
        #[Assert\Choice(choices: ['EUR', 'GBP', 'USD'])]
        public readonly ?string $currency = null,
        #[Assert\Choice(choices: ['travel', 'accommodation', 'meals', 'software', 'supplies', 'other'])]
        public readonly ?string $category = 'other',
        #[Assert\Date]
        public readonly ?string $expense_date = null,
    ) {
    }
}