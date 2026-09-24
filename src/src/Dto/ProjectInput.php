<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class ProjectInput
{
    public function __construct(
        #[Assert\NotBlank(allowNull: true)]
        #[Assert\Length(max: 255)]
        public readonly ?string $title = null,
        #[Assert\NotBlank(allowNull: true)]
        public readonly ?string $description = null,
    ) {
    }
}