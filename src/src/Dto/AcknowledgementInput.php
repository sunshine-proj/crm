<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body of the acknowledgement endpoints; each endpoint reads the fields it needs.
 * Targets: list of {kind: unit|position|user, id, include_subunits?}.
 */
final class AcknowledgementInput
{
    /**
     * @param list<array{kind: string, id: int, include_subunits?: bool}>|null $targets
     * @param list<int>|null $recipient_ids
     */
    public function __construct(
        #[Assert\Count(max: 200)]
        public readonly ?array $targets = null,
        #[Assert\Length(max: 4000)]
        public readonly ?string $message = null,
        #[Assert\Date]
        public readonly ?string $due_on = null,
        public readonly ?bool $require_open = null,
        public readonly ?bool $auto_add_joiners = null,
        #[Assert\Count(max: 1000)]
        #[Assert\All([new Assert\Type('integer'), new Assert\Positive()])]
        public readonly ?array $recipient_ids = null,
        #[Assert\Length(max: 1000)]
        public readonly ?string $reason = null,
        #[Assert\Positive]
        public readonly ?int $document_id = null,
    ) {
    }
}
