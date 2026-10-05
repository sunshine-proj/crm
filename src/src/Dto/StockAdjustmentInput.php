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
    ) {
    }
}