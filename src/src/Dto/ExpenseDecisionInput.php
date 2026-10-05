<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class ExpenseDecisionInput
{
    public function __construct(
        #[Assert\Choice(choices: ['approved', 'rejected'])]
        public readonly ?string $decision = null,
        #[Assert\Length(max: 255)]
        public readonly ?string $comment = null,
    ) {
    }
}