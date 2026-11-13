<?php

namespace App\Dto;

use App\Entity\DocumentLink;
use Symfony\Component\Validator\Constraints as Assert;

final class DocumentLinkInput
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Positive]
        public readonly ?int $target_id = null,
        #[Assert\NotBlank]
        #[Assert\Choice(choices: DocumentLink::KINDS)]
        public readonly ?string $kind = null,
    ) {
    }
}
