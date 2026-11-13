<?php

namespace App\Dto;

use App\Entity\OrgUnit;
use Symfony\Component\Validator\Constraints as Assert;

final class OrgUnitInput
{
    public function __construct(
        #[Assert\NotBlank(allowNull: true)]
        #[Assert\Length(max: 32)]
        public readonly ?string $code = null,
        #[Assert\NotBlank(allowNull: true)]
        #[Assert\Length(max: 255)]
        public readonly ?string $name = null,
        #[Assert\Choice(choices: OrgUnit::KINDS)]
        public readonly ?string $kind = null,
        #[Assert\Positive]
        public readonly ?int $parent_id = null,
        /** On update, set true to make the unit top-level (an organization). */
        public readonly bool $clear_parent = false,
    ) {
    }
}
