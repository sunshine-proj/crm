<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class HourlyCostRateInput
{
    public function __construct(
        public readonly ?string $hourly_rate = null,
        #[Assert\Choice(choices: ['EUR', 'GBP', 'USD'])]
        public readonly ?string $currency = null,
    ) {
    }
}
