<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class ProductInput
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 64)]
        public readonly ?string $sku = null,
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public readonly ?string $name = null,
        #[Assert\Length(max: 3, min: 3)]
        #[Assert\Choice(choices: ['EUR', 'GBP', 'USD'])]
        public readonly ?string $currency = null,
        public readonly ?string $unit_price = null,
        public readonly ?string $description = null,
        public readonly bool $active = true,
    ) {
    }
}