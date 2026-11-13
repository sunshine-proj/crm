<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** Body of the records-management actions (legal hold, review, disposal); each endpoint reads the fields it needs. */
final class DocumentRecordsInput
{
    public function __construct(
        #[Assert\Length(max: 1000)]
        public readonly ?string $reason = null,
        #[Assert\Length(max: 4000)]
        public readonly ?string $comment = null,
        #[Assert\Date]
        public readonly ?string $next_review_on = null,
    ) {
    }
}
