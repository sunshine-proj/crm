<?php

namespace App\Dto;

final class ActivityInput
{
    public function __construct(
        public readonly ?string $type = null,
        public readonly ?string $message = null,
    ) {
    }
}
