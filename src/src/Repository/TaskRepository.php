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

    /** @return list<Task> open tasks that have an assignee and an estimate, with assignee and project loaded */
    public function findOpenAssignedWithEstimate(): array
    {
        return $this->createQueryBuilder('task')
            ->innerJoin('task.assignee', 'assignee')->addSelect('assignee')
            ->leftJoin('task.project', 'project')->addSelect('project')
            ->where('task.status NOT IN (:closed)')
            ->andWhere('task.estimatedMinutes IS NOT NULL')
            ->setParameter('closed', [TaskStatus::Done->value, TaskStatus::Cancelled->value])
            ->getQuery()
            ->getResult();
    }

    /** Sum of task estimates on a project, and how many of its tasks have none. */
    public function estimateSummaryForProject(int $projectId): array
    {
        $row = $this->createQueryBuilder('task')
            ->select('COALESCE(SUM(task.estimatedMinutes), 0) AS estimated', 'SUM(CASE WHEN task.estimatedMinutes IS NULL THEN 1 ELSE 0 END) AS unestimated')
            ->where('IDENTITY(task.project) = :project')
            ->andWhere('task.status <> :cancelled')
            ->setParameter('project', $projectId)
            ->setParameter('cancelled', TaskStatus::Cancelled->value)
            ->getQuery()
            ->getSingleResult();

        return ['estimated_minutes' => (int) $row['estimated'], 'unestimated_tasks' => (int) $row['unestimated']];
    }
}
