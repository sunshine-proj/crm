<?php

namespace App\Dto;

use App\Entity\Document;
use App\Entity\DocumentType;
use Symfony\Component\Validator\Constraints as Assert;

final class DocumentTypeInput
{
    /** @param list<string>|null $required_fields */
    public function __construct(
        #[Assert\NotBlank(allowNull: true)]
        #[Assert\Length(max: 16)]
        public readonly ?string $code = null,
        #[Assert\NotBlank(allowNull: true)]
        #[Assert\Length(max: 255)]
        public readonly ?string $name = null,
        #[Assert\Length(max: 1000)]
        public readonly ?string $description = null,
        #[Assert\Length(max: 64)]
        public readonly ?string $number_pattern = null,
        public readonly ?bool $requires_approval = null,
        #[Assert\Choice(choices: Document::CONFIDENTIALITY_LEVELS)]
        public readonly ?string $default_confidentiality = null,
        #[Assert\Range(min: 1, max: 100)]
        public readonly ?int $retention_years = null,
        public readonly bool $clear_retention_years = false,
        #[Assert\All([new Assert\Choice(choices: DocumentType::REQUIRABLE_FIELDS)])]
        public readonly ?array $required_fields = null,
        public readonly ?bool $active = null,
        /** Default route: list of {kind: review|approve|sign, level: 1-8 up the reporting line, due_days?: 1-60}. */
        #[Assert\Count(max: 10)]
        public readonly ?array $route_template = null,
        public readonly ?bool $auto_register = null,
        #[Assert\Choice(choices: [DocumentType::RESTART, DocumentType::CONTINUE])]
        public readonly ?string $resubmit_mode = null,
    ) {
    }
}
