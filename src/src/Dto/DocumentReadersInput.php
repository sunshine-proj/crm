<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class DocumentReadersInput
{
    /** @param list<int> $user_ids */
    public function __construct(
        #[Assert\All([new Assert\Type('integer'), new Assert\Positive])]
        #[Assert\Count(max: 500)]
        public readonly array $user_ids = [],
    ) {
    }
}
