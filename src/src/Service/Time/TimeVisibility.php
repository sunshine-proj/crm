<?php

namespace App\Service\Time;

use App\Entity\User;
use App\Repository\ProjectRepository;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/** Single rule for who may see whose time entries; every time-entry read and report goes through it. */
final class TimeVisibility
{
    public function __construct(
        private readonly AuthorizationCheckerInterface $authorization,
        private readonly ProjectRepository $projectRepository,
    ) {
    }

    /** Scope of the authenticated user (role checks use the configured role hierarchy). */
    public function scopeFor(User $user): TimeScope
    {
        if ($this->authorization->isGranted('ROLE_FINANCE')) {
            return new TimeScope((int) $user->getId(), true);
        }

        return new TimeScope((int) $user->getId(), false, $this->projectRepository->findIdsManagedBy($user));
    }
}
