<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class PositionInput
{
    public function __construct(
        #[Assert\Positive]
        public readonly ?int $unit_id = null,
        #[Assert\NotBlank(allowNull: true)]
        #[Assert\Length(max: 255)]
        public readonly ?string $title = null,
        public readonly ?bool $is_head = null,
    ) {
    }
}
