<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class DocumentCommentInput
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Positive]
        public readonly ?int $version_number = null,
        #[Assert\NotBlank]
        #[Assert\Length(max: 4000)]
        public readonly ?string $body = null,
        #[Assert\Length(max: 500)]
        public readonly ?string $quote = null,
    ) {
    }
}
