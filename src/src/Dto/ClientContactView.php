<?php

namespace App\Dto;

final readonly class ClientContactView
{
    public function __construct(
        public int $id,
        public int $client_id,
        public string $name,
        public ?string $job_title,
        public ?string $email,
        public ?string $phone,
        public ?string $created_at,
        public ?string $updated_at,
    ) {
    }
}