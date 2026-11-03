<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class LineMatchInput
{
    public function __construct(
        #[Assert\Choice(choices: ['customer_payment', 'vendor_payment'])]
        public readonly ?string $type = null,
        #[Assert\Positive]
        public readonly ?int $id = null,
    ) {
    }
}
