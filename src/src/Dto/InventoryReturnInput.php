<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class InventoryReturnInput
{
    /** @param list<array<string, mixed>> $items */
    public function __construct(
        #[Assert\Choice(choices: ['customer', 'supplier'])]
        public readonly ?string $return_type = null,
        #[Assert\Choice(choices: ['sales_shipment', 'purchase_order'])]
        public readonly ?string $source_type = null,
        #[Assert\Positive]
        public readonly ?int $source_id = null,
        #[Assert\Positive]
        public readonly ?int $warehouse_id = null,
        #[Assert\Date]
        public readonly ?string $returned_at = null,
        #[Assert\Length(max: 80)]
        public readonly ?string $reference = null,
        #[Assert\Count(min: 1, max: 100)]
        public readonly array $items = [],
    ) {
    }
}