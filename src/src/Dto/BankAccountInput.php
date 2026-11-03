<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class BankAccountInput
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 120)]
        public readonly ?string $name = null,
        #[Assert\Length(max: 42)]
        public readonly ?string $iban = null,
        #[Assert\Choice(choices: ['EUR', 'GBP', 'USD'])]
        public readonly ?string $currency = null,
        #[Assert\Positive]
        public readonly ?int $ledger_account_id = null,
    ) {
    }
}
