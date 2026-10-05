<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class LedgerAccountInput
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 32)]
        public readonly ?string $code = null,
        #[Assert\NotBlank]
        #[Assert\Length(max: 160)]
        public readonly ?string $name = null,
        #[Assert\Choice(choices: ['asset', 'liability', 'equity', 'revenue', 'expense'])]
        public readonly ?string $type = null,
        public readonly bool $active = true,
    ) {
    }
}