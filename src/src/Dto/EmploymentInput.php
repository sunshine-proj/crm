<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class EmploymentInput
{
    public function __construct(
        #[Assert\Positive]
        public readonly ?int $user_id = null,
        #[Assert\Positive]
        public readonly ?int $position_id = null,
        #[Assert\Date]
        public readonly ?string $starts_on = null,
        #[Assert\Date]
        public readonly ?string $ends_on = null,
        public readonly ?bool $is_primary = null,
        public readonly ?bool $is_acting = null,
        #[Assert\Positive]
        public readonly ?int $reports_to_id = null,
        /** On update, set true to remove the end date or the reporting override that are otherwise kept when omitted. */
        public readonly bool $clear_ends_on = false,
        public readonly bool $clear_reports_to = false,
    ) {
    }
}
