<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class ClientContactInput
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public readonly ?string $name = null,
        #[Assert\Length(max: 150)]
        public readonly ?string $job_title = null,
        #[Assert\Email]
        #[Assert\Length(max: 180)]
        public readonly ?string $email = null,
        #[Assert\Length(max: 80)]
        public readonly ?string $phone = null,
    ) {
    }
}