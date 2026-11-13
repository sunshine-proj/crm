<?php

namespace App\Service\Org;

use App\Entity\Delegation;
use App\Entity\Employment;
use App\Entity\OrgUnit;
use App\Entity\User;

/**
 * The organization structure as it stands on one day: who holds which post, who heads which unit, who reports
 * to whom and who deputizes for whom. Read-only and built from loaded entities, so the subordination rules can be
 * tested without a database.
 *
 * Reporting rule: an employee's direct superior is the explicit `reportsTo` of their primary employment if set;
 * otherwise the head of their own unit, or of the parent unit when they head their own. Vacant head posts are
 * skipped, so the request goes to the next head up the tree.
 */
final class OrgChart
{
    /** Deputy chains longer than this are cut off (and cycles stop at the first repeat). */
    private const MAX_DEPUTY_HOPS = 5;

    /** @var array<int, OrgUnit> */
    private array $units = [];
    /** @var array<int, list<int>> unit id => child unit ids */
    private array $children = [];
    /** @var array<int, list<Employment>> user id => active employments, primary first */
    private array $employments = [];
    /** @var array<int, User> unit id => holder of the active head post */
    private array $heads = [];
    /** @var array<int, User> principal id => deputy */
    private array $deputies = [];
    /** @var array<int, User> principal id => principal */
    private array $principals = [];
    /** @var array<int, User> */
    private array $users = [];

    /**
     * @param iterable<OrgUnit> $units
     * @param iterable<Employment> $employments
     * @param iterable<Delegation> $delegations
     */
    public function __construct(private readonly \DateTimeImmutable $day, iterable $units, iterable $employments, iterable $delegations = [])
    {
        foreach ($units as $unit) {
            $this->units[(int) $unit->getId()] = $unit;
        }
        foreach ($this->units as $id => $unit) {
            if ($unit->getParent() !== null) {
                $this->children[(int) $unit->getParent()->getId()][] = $id;
            }
        }
        foreach ($employments as $employment) {
            $position = $employment->getPosition();
            if (!$employment->isActiveOn($day) || $position->isArchived() || $position->getUnit()->isArchived()) {
                continue;
            }
            $userId = (int) $employment->getUser()->getId();
            $this->users[$userId] = $employment->getUser();
            $this->employments[$userId][] = $employment;
            $unitId = (int) $position->getUnit()->getId();
            // A permanent head outranks an acting one if both are (wrongly) recorded for the same day.
            if ($position->isHead() && (!isset($this->heads[$unitId]) || !$employment->isActing())) {
                $this->heads[$unitId] = $employment->getUser();
            }
        }
        foreach ($this->employments as &$list) {
            usort($list, static fn (Employment $a, Employment $b): int => [$b->isPrimary(), $a->getStartsOn(), $a->getId()] <=> [$a->isPrimary(), $b->getStartsOn(), $b->getId()]);
        }
        unset($list);
        foreach ($delegations as $delegation) {
            if ($delegation->isActiveOn($day)) {
                $this->deputies[(int) $delegation->getPrincipal()->getId()] = $delegation->getDeputy();
                $this->principals[(int) $delegation->getPrincipal()->getId()] = $delegation->getPrincipal();
            }
        }
    }

    public function day(): \DateTimeImmutable { return $this->day; }

    public function unit(int $id): ?OrgUnit { return $this->units[$id] ?? null; }

    /** @return list<Employment> */
    public function employmentsOf(User $user): array
    {
        return $this->employments[(int) $user->getId()] ?? [];
    }

    /** The employment that places the user in the structure: the primary one, else the earliest other one. */
    public function primaryEmployment(User $user): ?Employment
    {
        return $this->employmentsOf($user)[0] ?? null;
    }

    public function headOf(int $unitId): ?User
    {
        return $this->heads[$unitId] ?? null;
    }

    public function directSuperior(User $user): ?User
    {
        $employment = $this->primaryEmployment($user);
        if ($employment === null) {
            return null;
        }
        $override = $employment->getReportsTo();
        if ($override !== null && $override->getId() !== $user->getId()) {
            return $override;
        }
        $unit = $employment->getPosition()->getUnit();
        if ($employment->getPosition()->isHead()) {
            $unit = $unit->getParent();
        }
        for ($guard = 0; $unit !== null && $guard <= OrgUnit::MAX_DEPTH; $unit = $unit->getParent(), ++$guard) {
            $head = $this->headOf((int) $unit->getId());
            if ($head !== null && $head->getId() !== $user->getId()) {
                return $head;
            }
        }

        return null;
    }

    /** @return list<User> the chain of superiors, nearest first; stops at the top or at a reporting cycle */
    public function superiors(User $user): array
    {
        $chain = [];
        $seen = [(int) $user->getId() => true];
        for ($current = $this->directSuperior($user); $current !== null && !isset($seen[(int) $current->getId()]); $current = $this->directSuperior($current)) {
            $seen[(int) $current->getId()] = true;
            $chain[] = $current;
        }

        return $chain;
    }

    public function isSuperiorOf(User $candidate, User $user): bool
    {
        foreach ($this->superiors($user) as $superior) {
            if ($superior->getId() === $candidate->getId()) {
                return true;
            }
        }

        return false;
    }

    /** @return list<User> direct or all (transitive) subordinates, ordered by e-mail */
    public function subordinates(User $manager, bool $directOnly = false): array
    {
        $result = [];
        foreach ($this->users as $id => $user) {
            if ($id === $manager->getId()) {
                continue;
            }
            $match = $directOnly ? $this->directSuperior($user)?->getId() === $manager->getId() : $this->isSuperiorOf($manager, $user);
            if ($match) {
                $result[] = $user;
            }
        }

        return $this->sortUsers($result);
    }

    /** @return list<int> ids of the units the user heads (including as acting head) and all their active sub-units */
    public function managedUnitIds(User $user): array
    {
        $ids = [];
        foreach ($this->heads as $unitId => $head) {
            if ($head->getId() === $user->getId()) {
                array_push($ids, ...$this->subtreeIds($unitId));
            }
        }

        return array_values(array_unique($ids));
    }

    /** @return list<int> the unit and its active descendants (archived branches excluded) */
    public function subtreeIds(int $unitId): array
    {
        if (!isset($this->units[$unitId]) || $this->units[$unitId]->isArchived()) {
            return [];
        }
        $ids = [$unitId];
        for ($i = 0; $i < count($ids); ++$i) {
            foreach ($this->children[$ids[$i]] ?? [] as $child) {
                if (!$this->units[$child]->isArchived()) {
                    $ids[] = $child;
                }
            }
        }

        return $ids;
    }

    /** @return list<User> everyone holding any active post in the unit (and its sub-units when asked), by e-mail */
    public function members(int $unitId, bool $includeSubunits = false): array
    {
        $unitIds = array_flip($includeSubunits ? $this->subtreeIds($unitId) : [$unitId]);
        $result = [];
        foreach ($this->employments as $userId => $list) {
            foreach ($list as $employment) {
                if (isset($unitIds[(int) $employment->getPosition()->getUnit()->getId()])) {
                    $result[$userId] = $this->users[$userId];
                    break;
                }
            }
        }

        return $this->sortUsers(array_values($result));
    }

    public function memberCount(int $unitId): int
    {
        return count($this->members($unitId));
    }

    /** The user who acts for the given one today, following deputy chains; null when nobody deputizes. */
    public function deputyFor(User $user): ?User
    {
        $deputy = null;
        $seen = [(int) $user->getId() => true];
        for ($current = $this->deputies[(int) $user->getId()] ?? null, $hops = 0; $current !== null && $hops < self::MAX_DEPUTY_HOPS; ++$hops) {
            if (isset($seen[(int) $current->getId()])) {
                break;
            }
            $seen[(int) $current->getId()] = true;
            $deputy = $current;
            $current = $this->deputies[(int) $current->getId()] ?? null;
        }

        return $deputy;
    }

    /** @return list<User> users the given user currently deputizes for */
    public function principalsOf(User $deputy): array
    {
        $result = [];
        foreach ($this->deputies as $principalId => $candidate) {
            if ($candidate->getId() === $deputy->getId()) {
                $result[] = $this->principals[$principalId];
            }
        }

        return $this->sortUsers($result);
    }

    /**
     * @param list<User> $users
     * @return list<User>
     */
    private function sortUsers(array $users): array
    {
        usort($users, static fn (User $a, User $b): int => [$a->getUserIdentifier(), $a->getId()] <=> [$b->getUserIdentifier(), $b->getId()]);

        return $users;
    }
}
