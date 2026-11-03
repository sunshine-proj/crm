<?php

namespace App\Repository;

use App\Entity\TimeEntryEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<TimeEntryEvent> */
class TimeEntryEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, TimeEntryEvent::class); }

    /** @return list<TimeEntryEvent> */
    public function findForEntry(int $timeEntryId): array
    {
        return $this->createQueryBuilder('event')
            ->leftJoin('event.actor', 'actor')->addSelect('actor')
            ->andWhere('IDENTITY(event.timeEntry) = :id')->setParameter('id', $timeEntryId)
            ->orderBy('event.createdAt', 'ASC')->addOrderBy('event.id', 'ASC')
            ->getQuery()->getResult();
    }
}
