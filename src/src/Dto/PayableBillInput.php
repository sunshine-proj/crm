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
    ) {
    }
}