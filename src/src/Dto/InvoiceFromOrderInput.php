<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class InvoiceFromOrderInput
{
    public function __construct(
        #[Assert\Date]
        public readonly ?string $invoice_date = null,
        #[Assert\Date]
        public readonly ?string $due_date = null,
    ) {
    }
}