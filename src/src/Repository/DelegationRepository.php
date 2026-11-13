<?php

namespace App\Repository;

use App\Entity\Delegation;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Delegation> */
class DelegationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, Delegation::class); }

    /** @return list<Delegation> unrevoked delegations covering the given day */
    public function findActiveOn(\DateTimeImmutable $day): array
    {
        return $this->createQueryBuilder('delegation')
            ->andWhere('delegation.revokedAt IS NULL')
            ->andWhere('delegation.startsOn <= :day')->andWhere('delegation.endsOn >= :day')
            ->setParameter('day', $day->setTime(0, 0), 'date_immutable')
            ->orderBy('delegation.id', 'ASC')
            ->getQuery()->getResult();
    }

    /** @return list<Delegation> delegations the user gave or received (all users when null), newest first */
    public function findInvolving(?User $user, bool $includePast, \DateTimeImmutable $today): array
    {
        $query = $this->createQueryBuilder('delegation')->orderBy('delegation.startsOn', 'DESC')->addOrderBy('delegation.id', 'DESC');
        if ($user !== null) {
            $query->andWhere('delegation.principal = :user OR delegation.deputy = :user')->setParameter('user', $user);
        }
        if (!$includePast) {
            $query->andWhere('delegation.revokedAt IS NULL')->andWhere('delegation.endsOn >= :today')->setParameter('today', $today->setTime(0, 0), 'date_immutable');
        }

        return $query->getQuery()->getResult();
    }

    public function hasOverlap(User $principal, \DateTimeImmutable $startsOn, \DateTimeImmutable $endsOn): bool
    {
        return (int) $this->createQueryBuilder('delegation')->select('COUNT(delegation.id)')
            ->andWhere('delegation.principal = :principal')->setParameter('principal', $principal)
            ->andWhere('delegation.revokedAt IS NULL')
            ->andWhere('delegation.startsOn <= :end')->andWhere('delegation.endsOn >= :start')
            ->setParameter('start', $startsOn->setTime(0, 0), 'date_immutable')->setParameter('end', $endsOn->setTime(0, 0), 'date_immutable')
            ->getQuery()->getSingleScalarResult() > 0;
    }
}
