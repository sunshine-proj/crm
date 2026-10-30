<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class ApprovedTimeInvoiceInput
{
    /**
     * @param list<int> $entry_ids
     */
    public function __construct(
        #[Assert\Count(min: 1, max: 100)]
        public readonly array $entry_ids = [],
        #[Assert\Date]
        public readonly ?string $invoice_date = null,
        #[Assert\Date]
        public readonly ?string $due_date = null,
    ) {
    }
}
