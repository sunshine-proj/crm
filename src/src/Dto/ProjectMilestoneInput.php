<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class ProjectMilestoneInput
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public readonly ?string $title = null,
        #[Assert\Length(max: 5000)]
        public readonly ?string $description = null,
        #[Assert\Date]
        public readonly ?string $due_date = null,
        public readonly ?string $amount = null,
        #[Assert\Choice(choices: ['EUR', 'GBP', 'USD'])]
        public readonly ?string $currency = null,
    ) {
    }
}
