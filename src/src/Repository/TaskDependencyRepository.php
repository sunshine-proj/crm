<?php

namespace App\Repository;

use App\Entity\TaskDependency;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class TaskDependencyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TaskDependency::class);
    }

    /** @return list<TaskDependency> */
    public function findForSuccessor(int $taskId): array
    {
        return $this->createQueryBuilder('dependency')
            ->leftJoin('dependency.predecessor', 'predecessor')->addSelect('predecessor')
            ->andWhere('IDENTITY(dependency.successor) = :taskId')
            ->setParameter('taskId', $taskId)
            ->getQuery()
            ->getResult();
    }
}
