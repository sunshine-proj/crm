<?php

namespace App\Dto;

/** Validated document list filters. */
final class DocumentSearch
{
    public function __construct(
        public readonly ?string $text = null,
        public readonly ?int $typeId = null,
        public readonly ?string $status = null,
        public readonly ?int $ownerUnitId = null,
        public readonly ?int $authorId = null,
        public readonly ?string $tag = null,
        public readonly ?int $clientId = null,
        public readonly ?int $projectId = null,
        public readonly ?\DateTimeImmutable $from = null,
        public readonly ?\DateTimeImmutable $to = null,
        public readonly int $limit = 50,
        public readonly int $offset = 0,
    ) {
    }
}
