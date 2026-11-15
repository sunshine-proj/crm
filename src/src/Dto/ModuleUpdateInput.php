<?php

namespace App\Dto;

/** Administrator change to one module: any omitted field keeps its current value. */
final class ModuleUpdateInput
{
    /**
     * @param list<mixed>|null $allowed_roles
     * @param array<string, mixed>|null $settings
     */
    public function __construct(
        public readonly ?bool $enabled = null,
        public readonly ?array $allowed_roles = null,
        public readonly ?array $settings = null,
    ) {
    }
}
