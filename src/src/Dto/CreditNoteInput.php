<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class CreditNoteInput
{
    /** @param list<array<string, mixed>> $items lines to credit (description, net amount, tax_code); empty credits the whole invoice */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 500)]
        public readonly ?string $reason = null,
        #[Assert\Date]
        public readonly ?string $credit_date = null,
        #[Assert\Count(max: 100)]
        public readonly array $items = [],
    ) {
    }
}
