<?php

namespace App\Dto;

final class NoteInput
{
    public function __construct(
        public readonly ?string $content = null,
    ) {
    }
}
