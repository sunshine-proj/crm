<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class TaskDependenciesInput
{
    /** @param list<int> $depends_on_ids */
    public function __construct(
        #[Assert\Count(max: 100)]
        public readonly array $depends_on_ids = [],
    ) {
    }
}
