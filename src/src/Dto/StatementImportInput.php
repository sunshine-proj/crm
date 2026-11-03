<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class StatementImportInput
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public readonly ?string $filename = null,
        #[Assert\Choice(choices: ['camt053', 'csv'])]
        public readonly ?string $format = null,
        /** File content as text (UTF-8). */
        #[Assert\NotBlank]
        public readonly ?string $content = null,
    ) {
    }
}
