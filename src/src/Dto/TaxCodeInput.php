<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class TaxCodeInput
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 32)]
        public readonly ?string $code = null,
        #[Assert\NotBlank]
        #[Assert\Length(max: 160)]
        public readonly ?string $name = null,
        public readonly ?string $rate_percent = null,
        public readonly bool $active = true,
    ) {
    }
}