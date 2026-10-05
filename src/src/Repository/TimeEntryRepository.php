<?php

namespace App\Repository;

use App\Entity\TimeEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<TimeEntry> */
class TimeEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, TimeEntry::class); }

    /** @return list<TimeEntry> */
    public function findForProject(int $projectId): array
    {
        return $this->createQueryBuilder('entry')
            ->leftJoin('entry.user', 'user')->addSelect('user')
            ->leftJoin('entry.task', 'task')->addSelect('task')
            ->andWhere('IDENTITY(entry.project) = :projectId')
            ->setParameter('projectId', $projectId)
            ->orderBy('entry.workDate', 'DESC')->addOrderBy('entry.id', 'DESC')
            ->getQuery()->getResult();
    }
}