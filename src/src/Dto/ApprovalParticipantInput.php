<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** A person to add to a running approval: a reviewer (author) or a consultee (approver forwarding for review). */
final class ApprovalParticipantInput
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Positive]
        public readonly ?int $assignee_id = null,
        #[Assert\Date]
        public readonly ?string $due_on = null,
        #[Assert\Length(max: 4000)]
        public readonly ?string $comment = null,
    ) {
    }
}
