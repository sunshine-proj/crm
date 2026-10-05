<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class SalesQuoteStatusInput
{
    public function __construct(
        #[Assert\Choice(choices: ['sent', 'accepted', 'rejected'])]
        public readonly ?string $status = null,
    ) {
    }
}