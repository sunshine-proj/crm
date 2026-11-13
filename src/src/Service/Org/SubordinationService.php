<?php

namespace App\Service\Org;

use App\Repository\DelegationRepository;
use App\Repository\EmploymentRepository;
use App\Repository\OrgUnitRepository;

/** Builds the organization chart for a day; the single entry point for "who reports to whom" questions. */
final class SubordinationService
{
    public function __construct(
        private readonly OrgUnitRepository $unitRepository,
        private readonly EmploymentRepository $employmentRepository,
        private readonly DelegationRepository $delegationRepository,
    ) {
    }

    public function chartOn(?\DateTimeImmutable $day = null): OrgChart
    {
        $day = ($day ?? new \DateTimeImmutable('today'))->setTime(0, 0);

        return new OrgChart(
            $day,
            $this->unitRepository->findAllOrdered(),
            $this->employmentRepository->findActiveOn($day),
            $this->delegationRepository->findActiveOn($day),
        );
    }
}
