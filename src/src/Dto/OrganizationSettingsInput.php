<?php

namespace App\Dto;

use App\Entity\OrganizationSettings;
use Symfony\Component\Validator\Constraints as Assert;

final class OrganizationSettingsInput
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Choice(choices: OrganizationSettings::SUPPORTED_COUNTRIES)]
        public ?string $country_code = null,
        #[Assert\Choice(choices: OrganizationSettings::SUPPORTED_CURRENCIES)]
        public ?string $default_currency = null,
        #[Assert\NotNull]
        #[Assert\Range(min: 1, max: 100)]
        public ?int $retention_years = null,
    ) {
    }
}
