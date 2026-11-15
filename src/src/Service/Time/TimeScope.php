<?php

namespace App\Service\Time;

use App\Entity\TimeEntry;

/**
 * Which time entries one user may see: every entry (administrators and finance), entries on the projects they
 * manage plus their own (project managers), or only their own (everyone else).
 */
final class TimeScope
{
    public const ALL = 'all';
    public const MANAGED = 'managed';
    public const OWN = 'own';

    /** @param list<int> $managedProjectIds */
    public function __construct(
        private readonly int $userId,
        private readonly bool $all,
        private readonly array $managedProjectIds = [],
    ) {
    }

    public function userId(): int
    {
        return $this->userId;
    }

    public function seesEverything(): bool
    {
        return $this->all;
    }

    /** @return list<int> */
    public function managedProjectIds(): array
    {
        return $this->managedProjectIds;
    }

    public function level(): string
    {
        if ($this->all) {
            return self::ALL;
        }

        return $this->managedProjectIds === [] ? self::OWN : self::MANAGED;
    }

    /** Whether every employee's time on the project is visible (not only the viewer's own). */
    public function seesWholeProject(int $projectId): bool
    {
        return $this->all || in_array($projectId, $this->managedProjectIds, true);
    }

    public function canSee(TimeEntry $entry): bool
    {
        return $entry->getUser()->getId() === $this->userId || $this->seesWholeProject((int) $entry->getProject()->getId());
    }
}
