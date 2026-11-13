<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** Route for an approval round; omit `stages` to use the type's template or the previous round. */
final class ApprovalSubmitInput
{
    /** @param list<array{rule?: string, steps: list<array{assignee_id: int, kind?: string, due_on?: string}>}>|null $stages */
    public function __construct(
        #[Assert\Count(max: 10)]
        public readonly ?array $stages = null,
        #[Assert\Length(max: 4000)]
        public readonly ?string $comment = null,
    ) {
    }
}
