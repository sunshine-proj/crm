<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class LegalProfileInput
{
    public function __construct(
        #[Assert\Length(max: 255)]
        public readonly ?string $legal_name = null,
        #[Assert\Length(max: 64)]
        public readonly ?string $registration_number = null,
        #[Assert\Length(max: 32)]
        public readonly ?string $vat_number = null,
        #[Assert\Length(max: 2000)]
        public readonly ?string $address = null,
        #[Assert\Length(max: 180)]
        public readonly ?string $email = null,
        #[Assert\Length(max: 2000)]
        public readonly ?string $payment_instructions = null,
    ) {
    }
}
