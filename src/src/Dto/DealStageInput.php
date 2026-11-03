<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class DealStageInput
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Choice(choices: ['lead', 'qualified', 'proposal', 'negotiation', 'won', 'lost'])]
        public readonly ?string $stage = null,
        /** Required when the stage is "lost"; optional context otherwise. */
        #[Assert\Length(max: 500)]
        public readonly ?string $note = null,
    ) {
    }
}
