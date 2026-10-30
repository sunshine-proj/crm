<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class FiscalPeriodControlInput
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 500)]
        public readonly ?string $reason = null,
    ) {
    }
}
