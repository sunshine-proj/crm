<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class ShipmentDispatchInput
{
    public function __construct(
        #[Assert\Positive]
        public readonly ?int $warehouse_id = null,
        #[Assert\Date]
        public readonly ?string $moved_at = null,
    ) {
    }
}