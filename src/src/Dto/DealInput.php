<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class DealInput
{
    public function __construct(
        #[Assert\Positive]
        public readonly ?int $client_id = null,
        #[Assert\NotBlank(allowNull: true)]
        #[Assert\Length(max: 255)]
        public readonly ?string $title = null,
        public readonly ?string $amount = null,
        #[Assert\Choice(choices: ['EUR', 'GBP', 'USD'])]
        public readonly ?string $currency = null,
        #[Assert\Date]
        public readonly ?string $expected_close_date = null,
        #[Assert\Positive]
        public readonly ?int $owner_id = null,
        /** Set true on update to remove the amount, close date, or owner that are otherwise left unchanged when omitted. */
        public readonly bool $clear_amount = false,
        public readonly bool $clear_close_date = false,
        public readonly bool $clear_owner = false,
    ) {
    }
}
