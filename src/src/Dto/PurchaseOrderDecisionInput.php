<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class PurchaseOrderDecisionInput
{
    public function __construct(
        #[Assert\Length(max: 500)]
        public readonly ?string $comment = null,
    ) {
    }
}
