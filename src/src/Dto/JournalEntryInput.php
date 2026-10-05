<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class JournalEntryInput
{
    /** @param list<array<string, mixed>> $lines */
    public function __construct(
        #[Assert\Date]
        public readonly ?string $entry_date = null,
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public readonly ?string $description = null,
        #[Assert\Choice(choices: ['EUR', 'GBP', 'USD'])]
        public readonly ?string $currency = null,
        #[Assert\Count(min: 2, max: 100)]
        public readonly array $lines = [],
    ) {
    }
}