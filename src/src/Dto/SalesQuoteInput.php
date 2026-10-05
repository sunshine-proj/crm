<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class SalesQuoteInput
{
    /** @param list<array<string, mixed>> $items */
    public function __construct(
        #[Assert\Positive]
        public readonly ?int $client_id = null,
        #[Assert\Choice(choices: ['EUR', 'GBP', 'USD'])]
        public readonly ?string $currency = null,
        #[Assert\Date]
        public readonly ?string $valid_until = null,
        #[Assert\Count(min: 1, max: 100)]
        public readonly array $items = [],
    ) {
    }
}