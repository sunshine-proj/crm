<?php

namespace App\Service\Org;

use App\Entity\Delegation;
use App\Entity\Employment;
use App\Entity\OrgUnit;
use App\Entity\Position;
use App\Entity\User;

/** JSON response shapes for organization records (snake_case, ISO dates), shared by the org and DMS controllers. */
final class OrgViews
{
    /** @return array{id: int|null, email: string, position_title: string|null, unit_id: int|null, unit_name: string|null} */
    public function person(User $user, OrgChart $chart): array
    {
        $employment = $chart->primaryEmployment($user);

        return [
            'id' => $user->getId(),
            'email' => $user->getUserIdentifier(),
            'position_title' => $employment?->getPosition()->getTitle(),
            'unit_id' => $employment?->getPosition()->getUnit()->getId(),
            'unit_name' => $employment?->getPosition()->getUnit()->getName(),
        ];
    }

    /**
     * @param list<User> $users
     * @return list<array<string, mixed>>
     */
    public function people(array $users, OrgChart $chart): array
    {
        return array_map(fn (User $user): array => $this->person($user, $chart), $users);
    }

    /** @return array<string, mixed> */
    public function unit(OrgUnit $unit, OrgChart $chart): array
    {
        $head = $chart->headOf((int) $unit->getId());

        return [
            'id' => $unit->getId(),
            'parent_id' => $unit->getParent()?->getId(),
            'code' => $unit->getCode(),
            'name' => $unit->getName(),
            'kind' => $unit->getKind(),
            'depth' => $unit->depth(),
            'head' => $head !== null ? ['id' => $head->getId(), 'email' => $head->getUserIdentifier()] : null,
            'member_count' => $unit->isArchived() ? 0 : $chart->memberCount((int) $unit->getId()),
            'archived_at' => $unit->getArchivedAt()?->format(\DateTimeInterface::ATOM),
            'created_at' => $unit->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $unit->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param list<Employment> $holders active employments on the position
     * @return array<string, mixed>
     */
    public function position(Position $position, array $holders = []): array
    {
        return [
            'id' => $position->getId(),
            'unit_id' => $position->getUnit()->getId(),
            'unit_name' => $position->getUnit()->getName(),
            'title' => $position->getTitle(),
            'is_head' => $position->isHead(),
            'holders' => array_map(static fn (Employment $employment): array => [
                'employment_id' => $employment->getId(), 'user_id' => $employment->getUser()->getId(),
                'email' => $employment->getUser()->getUserIdentifier(), 'is_acting' => $employment->isActing(),
            ], $holders),
            'archived_at' => $position->getArchivedAt()?->format(\DateTimeInterface::ATOM),
            'created_at' => $position->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public function employment(Employment $employment, \DateTimeImmutable $today): array
    {
        $position = $employment->getPosition();

        return [
            'id' => $employment->getId(),
            'user_id' => $employment->getUser()->getId(),
            'user_email' => $employment->getUser()->getUserIdentifier(),
            'position_id' => $position->getId(),
            'position_title' => $position->getTitle(),
            'is_head' => $position->isHead(),
            'unit_id' => $position->getUnit()->getId(),
            'unit_name' => $position->getUnit()->getName(),
            'is_primary' => $employment->isPrimary(),
            'is_acting' => $employment->isActing(),
            'starts_on' => $employment->getStartsOn()->format('Y-m-d'),
            'ends_on' => $employment->getEndsOn()?->format('Y-m-d'),
            'reports_to_id' => $employment->getReportsTo()?->getId(),
            'reports_to_email' => $employment->getReportsTo()?->getUserIdentifier(),
            'is_active' => $employment->isActiveOn($today),
            'created_at' => $employment->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $employment->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public function delegation(Delegation $delegation, \DateTimeImmutable $today): array
    {
        return [
            'id' => $delegation->getId(),
            'principal_id' => $delegation->getPrincipal()->getId(),
            'principal_email' => $delegation->getPrincipal()->getUserIdentifier(),
            'deputy_id' => $delegation->getDeputy()->getId(),
            'deputy_email' => $delegation->getDeputy()->getUserIdentifier(),
            'starts_on' => $delegation->getStartsOn()->format('Y-m-d'),
            'ends_on' => $delegation->getEndsOn()->format('Y-m-d'),
            'reason' => $delegation->getReason(),
            'is_active' => $delegation->isActiveOn($today),
            'created_by' => $delegation->getCreatedBy(),
            'created_at' => $delegation->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'revoked_at' => $delegation->getRevokedAt()?->format(\DateTimeInterface::ATOM),
            'revoked_by' => $delegation->getRevokedBy(),
        ];
    }
}
