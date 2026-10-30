<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class JournalReversalInput
{
    public function __construct(
        #[Assert\Date]
        public readonly ?string $entry_date = null,
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public readonly ?string $reason = null,
    ) {
    }
}
