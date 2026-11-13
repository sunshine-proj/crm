<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class ApprovalDecisionInput
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Choice(choices: ['approve', 'reject', 'return', 'review'])]
        public readonly ?string $decision = null,
        #[Assert\Length(max: 4000)]
        public readonly ?string $comment = null,
    ) {
    }
}
