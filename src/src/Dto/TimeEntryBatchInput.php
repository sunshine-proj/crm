<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** Several time entries submitted together from the weekly timesheet; elements are mapped with TimeEntryInput::fromArray(). */
final class TimeEntryBatchInput
{
    /** @param list<mixed> $entries */
    public function __construct(
        #[Assert\Count(min: 1, max: 100)]
        public readonly array $entries = [],
    ) {
    }
}
