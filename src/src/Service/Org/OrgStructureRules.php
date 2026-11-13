<?php

namespace App\Service\Org;

use App\Entity\Employment;
use App\Entity\OrgUnit;
use App\Entity\Position;
use App\Entity\User;
use App\Repository\EmploymentRepository;
use App\Repository\OrgUnitRepository;
use App\Repository\PositionRepository;

/**
 * Write-side invariants of the organization structure that need the database. Each check throws
 * \DomainException with a user-facing message (the API answers 409).
 */
final class OrgStructureRules
{
    public function __construct(
        private readonly OrgUnitRepository $unitRepository,
        private readonly PositionRepository $positionRepository,
        private readonly EmploymentRepository $employmentRepository,
        private readonly SubordinationService $subordination,
    ) {
    }

    /** The moved unit's whole subtree must still fit below the new parent. */
    public function assertSubtreeFits(OrgUnit $unit, ?OrgUnit $newParent): void
    {
        $base = $newParent === null ? 0 : $newParent->depth();
        if ($base + $this->height($unit) > OrgUnit::MAX_DEPTH) {
            throw new \DomainException(sprintf('The structure is limited to %d levels.', OrgUnit::MAX_DEPTH));
        }
    }

    public function assertSingleHead(OrgUnit $unit, ?Position $position = null): void
    {
        if ($this->positionRepository->findActiveHead($unit, $position) !== null) {
            throw new \DomainException('The unit already has a head position.');
        }
    }

    public function assertUnitCanBeArchived(OrgUnit $unit): void
    {
        if ($this->unitRepository->hasChildren($unit, true)) {
            throw new \DomainException('Archive or move the sub-units first.');
        }
        $today = new \DateTimeImmutable('today');
        foreach ($this->positionRepository->findAllOrdered($unit->getId()) as $position) {
            if ($this->employmentRepository->countForPosition($position, $today) > 0) {
                throw new \DomainException('End the current and future employments in this unit first.');
            }
        }
    }

    public function assertUnitCanBeDeleted(OrgUnit $unit): void
    {
        if ($this->unitRepository->hasChildren($unit)) {
            throw new \DomainException('A unit with sub-units cannot be deleted; archive it instead.');
        }
        if ($this->positionRepository->countForUnit($unit) > 0) {
            throw new \DomainException('A unit that has positions cannot be deleted; archive it instead.');
        }
    }

    public function assertPositionCanBeArchived(Position $position): void
    {
        if ($this->employmentRepository->countForPosition($position, new \DateTimeImmutable('today')) > 0) {
            throw new \DomainException('End the current and future employments on this position first.');
        }
    }

    public function assertPositionCanBeDeleted(Position $position): void
    {
        if ($this->employmentRepository->countForPosition($position) > 0) {
            throw new \DomainException('A position that has been held cannot be deleted; archive it instead.');
        }
    }

    /** At most one primary post per person and one holder per head post at a time; no double booking of a post. */
    public function assertEmploymentFits(Employment $employment): void
    {
        $start = $employment->getStartsOn();
        $end = $employment->getEndsOn();
        foreach ($this->employmentRepository->findOverlapping($employment->getUser(), null, $start, $end, $employment) as $other) {
            if ($other->getPosition() === $employment->getPosition()) {
                throw new \DomainException('The employee already holds this position in that period.');
            }
            if ($other->isPrimary() && $employment->isPrimary()) {
                throw new \DomainException('The employee already has a primary position in that period.');
            }
        }
        if ($employment->getPosition()->isHead() && $this->employmentRepository->findOverlapping(null, $employment->getPosition(), $start, $end, $employment) !== []) {
            throw new \DomainException('The head position is already held in that period.');
        }
    }

    /** Refuses a reporting override that would make the chain of superiors loop back to the employee. */
    public function assertNoReportingCycle(User $employee, ?User $superior): void
    {
        if ($superior === null) {
            return;
        }
        $chart = $this->subordination->chartOn();
        if ($superior->getId() === $employee->getId() || $chart->isSuperiorOf($employee, $superior)) {
            throw new \DomainException('That reporting line would create a cycle.');
        }
    }

    /** Levels in the unit's subtree, the unit itself counting as one. */
    private function height(OrgUnit $unit): int
    {
        $children = [];
        foreach ($this->unitRepository->findAllOrdered() as $candidate) {
            if ($candidate->getParent() !== null) {
                $children[spl_object_id($candidate->getParent())][] = $candidate;
            }
        }
        $height = 0;
        for ($level = [$unit]; $level !== [] && $height <= OrgUnit::MAX_DEPTH; ++$height) {
            $next = [];
            foreach ($level as $node) {
                array_push($next, ...($children[spl_object_id($node)] ?? []));
            }
            $level = $next;
        }

        return $height;
    }
}
