<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class StockAdjustmentInput
{
    public function __construct(
        #[Assert\Positive]
        public readonly ?int $product_id = null,
        #[Assert\Positive]
        public readonly ?int $warehouse_id = null,
        public readonly ?int $quantity_delta = null,
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public readonly ?string $reason = null,
        #[Assert\Date]
        public readonly ?string $moved_at = null,
        /** Unit cost of extra stock when the warehouse holds no valued stock of the product. */
        public readonly ?string $unit_cost = null,
        #[Assert\Choice(choices: ['EUR', 'GBP', 'USD'])]
        public readonly ?string $currency = null,
    ) {
    }
}