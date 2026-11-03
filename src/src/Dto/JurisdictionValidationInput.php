<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class JurisdictionValidationInput
{
    public function __construct(
        #[Assert\Choice(choices: ['LV', 'LT', 'EE', 'DE', 'GB'])]
        public readonly ?string $country_code = null,
        #[Assert\Choice(choices: ['tax', 'numbering'])]
        public readonly ?string $scope = null,
        #[Assert\NotBlank]
        #[Assert\Length(max: 160)]
        public readonly ?string $adviser_name = null,
        #[Assert\Length(max: 160)]
        public readonly ?string $adviser_firm = null,
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public readonly ?string $reference = null,
        #[Assert\Date]
        public readonly ?string $validated_on = null,
        #[Assert\Choice(choices: ['per_rate_group', 'per_line'])]
        public readonly ?string $rounding = null,
        #[Assert\Length(max: 1000)]
        public readonly ?string $notes = null,
    ) {
    }
}
