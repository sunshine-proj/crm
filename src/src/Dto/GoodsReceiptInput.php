<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class GoodsReceiptInput
{
    /** @param list<array<string, mixed>> $items */
    public function __construct(
        #[Assert\Positive]
        public readonly ?int $warehouse_id = null,
        #[Assert\Date]
        public readonly ?string $received_at = null,
        #[Assert\Length(max: 80)]
        public readonly ?string $reference = null,
        #[Assert\Count(min: 1, max: 100)]
        public readonly array $items = [],
    ) {
    }
}