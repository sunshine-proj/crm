<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class DelegationInput
{
    public function __construct(
        #[Assert\Positive]
        public readonly ?int $deputy_id = null,
        /** Administrators may record a delegation on someone else's behalf; others always delegate for themselves. */
        #[Assert\Positive]
        public readonly ?int $principal_id = null,
        #[Assert\NotBlank]
        #[Assert\Date]
        public readonly ?string $starts_on = null,
        #[Assert\NotBlank]
        #[Assert\Date]
        public readonly ?string $ends_on = null,
        #[Assert\Length(max: 255)]
        public readonly ?string $reason = null,
    ) {
    }
}
