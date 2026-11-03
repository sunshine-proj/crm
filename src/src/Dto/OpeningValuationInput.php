<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class OpeningValuationInput
{
    public function __construct(
        #[Assert\Positive]
        public readonly ?int $product_id = null,
        #[Assert\Positive]
        public readonly ?int $warehouse_id = null,
        public readonly ?string $unit_cost = null,
        #[Assert\Choice(choices: ['EUR', 'GBP', 'USD'])]
        public readonly ?string $currency = null,
        #[Assert\Date]
        public readonly ?string $valuation_date = null,
    ) {
    }
}
