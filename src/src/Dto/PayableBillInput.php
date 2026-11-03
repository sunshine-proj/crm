<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class PayableBillInput
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public readonly ?string $vendor_name = null,
        #[Assert\NotBlank]
        #[Assert\Length(max: 80)]
        public readonly ?string $vendor_reference = null,
        #[Assert\Choice(choices: ['EUR', 'GBP', 'USD'])]
        public readonly ?string $currency = null,
        public readonly ?string $total = null,
        #[Assert\Date]
        public readonly ?string $bill_date = null,
        #[Assert\Date]
        public readonly ?string $due_date = null,
        /** Links the bill to a purchase order for three-way matching against goods received. */
        #[Assert\Positive]
        public readonly ?int $purchase_order_id = null,
        /** Required to accept a bill that exceeds the received, not yet invoiced value of its purchase order. */
        #[Assert\Length(max: 500)]
        public readonly ?string $override_reason = null,
    ) {
    }
}