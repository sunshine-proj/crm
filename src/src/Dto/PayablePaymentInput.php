<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class PayablePaymentInput
{
    public function __construct(
        public readonly ?string $amount = null,
        #[Assert\Date]
        public readonly ?string $paid_at = null,
        #[Assert\Length(max: 120)]
        public readonly ?string $reference = null,
    ) {
    }
}