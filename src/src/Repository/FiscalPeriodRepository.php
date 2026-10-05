<?php

namespace App\Repository;

use App\Entity\FiscalPeriod;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<FiscalPeriod> */
class FiscalPeriodRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FiscalPeriod::class);
    }

    public function findContaining(\DateTimeImmutable $date): ?FiscalPeriod
    {
        return $this->createQueryBuilder('period')
            ->andWhere('period.startsOn <= :date')
            ->andWhere('period.endsOn >= :date')
            ->setParameter('date', $date)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function hasOverlap(\DateTimeImmutable $startsOn, \DateTimeImmutable $endsOn): bool
    {
        return $this->createQueryBuilder('period')
            ->select('COUNT(period.id)')
            ->andWhere('period.startsOn <= :endsOn')
            ->andWhere('period.endsOn >= :startsOn')
            ->setParameter('startsOn', $startsOn)
            ->setParameter('endsOn', $endsOn)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }
}