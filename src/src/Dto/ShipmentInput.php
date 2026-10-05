<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class ShipmentInput
{
    /** @param list<array<string, mixed>> $items */
    public function __construct(
        #[Assert\Date]
        public readonly ?string $shipped_at = null,
        #[Assert\Length(max: 64)]
        public readonly ?string $reference = null,
        #[Assert\Count(min: 1, max: 100)]
        public readonly array $items = [],
    ) {
    }
}