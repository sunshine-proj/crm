<?php

namespace App\Dto;

use App\Entity\Document;
use Symfony\Component\Validator\Constraints as Assert;

/** Create/update payload for a managed document; on update, omitted fields are kept and clear_* flags empty them. */
final class DocumentInput
{
    /** @param list<string>|null $tags */
    public function __construct(
        #[Assert\Positive]
        public readonly ?int $type_id = null,
        #[Assert\NotBlank(allowNull: true)]
        #[Assert\Length(max: 255)]
        public readonly ?string $title = null,
        #[Assert\Length(max: 5000)]
        public readonly ?string $summary = null,
        #[Assert\Positive]
        public readonly ?int $owner_unit_id = null,
        #[Assert\Choice(choices: Document::CONFIDENTIALITY_LEVELS)]
        public readonly ?string $confidentiality = null,
        #[Assert\Date]
        public readonly ?string $effective_on = null,
        #[Assert\Date]
        public readonly ?string $expires_on = null,
        #[Assert\Date]
        public readonly ?string $review_on = null,
        #[Assert\All([new Assert\Type('string'), new Assert\Length(max: 40)])]
        #[Assert\Count(max: 20)]
        public readonly ?array $tags = null,
        #[Assert\Positive]
        public readonly ?int $client_id = null,
        #[Assert\Positive]
        public readonly ?int $project_id = null,
        #[Assert\Positive]
        public readonly ?int $deal_id = null,
        public readonly bool $clear_owner_unit = false,
        public readonly bool $clear_effective_on = false,
        public readonly bool $clear_expires_on = false,
        public readonly bool $clear_review_on = false,
        public readonly bool $clear_client = false,
        public readonly bool $clear_project = false,
        public readonly bool $clear_deal = false,
    ) {
    }
}
