<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class SupplierInput
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 32)]
        public readonly ?string $code = null,
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public readonly ?string $name = null,
        #[Assert\Email]
        #[Assert\Length(max: 180)]
        public readonly ?string $email = null,
        #[Assert\Length(max: 80)]
        public readonly ?string $phone = null,
        public readonly ?string $address = null,
        public readonly bool $active = true,
    ) {
    }
}