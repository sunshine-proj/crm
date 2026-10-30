<?php

namespace App\Repository;

use App\Entity\Task;
use App\Entity\TaskStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class TaskRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Task::class);
    }

    public function countOpen(): int
    {
        return (int) $this->createQueryBuilder('task')
            ->select('COUNT(task.id)')
            ->where('task.status NOT IN (:closed)')
            ->setParameter('closed', [TaskStatus::Done->value, TaskStatus::Cancelled->value])
            ->getQuery()
            ->getSingleScalarResult();
    }
}