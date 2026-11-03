<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class ClientInput
{
    public function __construct(
        #[Assert\NotBlank(allowNull: true)]
        #[Assert\Length(max: 255)]
        public readonly ?string $title = null,
        #[Assert\NotBlank(allowNull: true)]
        public readonly ?string $notes = null,
        #[Assert\Length(max: 255)]
        public readonly ?string $legal_name = null,
        #[Assert\Length(max: 64)]
        public readonly ?string $registration_number = null,
        #[Assert\Length(max: 32)]
        public readonly ?string $vat_number = null,
        #[Assert\Regex('/^([A-Za-z]{2})?$/')]
        public readonly ?string $country_code = null,
        #[Assert\Length(max: 2000)]
        public readonly ?string $billing_address = null,
        #[Assert\Length(max: 180)]
        public readonly ?string $billing_email = null,
        #[Assert\Length(max: 255)]
        public readonly ?string $website = null,
        #[Assert\Length(max: 120)]
        public readonly ?string $industry = null,
        #[Assert\Choice(choices: ['prospect', 'active', 'inactive'])]
        public readonly ?string $account_status = null,
    ) {
    }
}
