<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class FiscalPeriodInput
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 80)]
        public readonly ?string $name = null,
        #[Assert\Date]
        public readonly ?string $starts_on = null,
        #[Assert\Date]
        public readonly ?string $ends_on = null,
    ) {
    }
}