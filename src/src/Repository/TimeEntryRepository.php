<?php

namespace App\Repository;

use App\Entity\TimeEntry;
use App\Entity\User;
use App\Service\Time\TimeEntryFilter;
use App\Service\Time\TimeScope;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<TimeEntry> */
class TimeEntryRepository extends ServiceEntityRepository
{
    /** Report dimensions: selected id/label columns, in the order they are grouped. */
    private const DIMENSIONS = [
        'employee' => ['user.id' => 'employee_id', 'user.email' => 'employee_email'],
        'project' => ['project.id' => 'project_id', 'project.title' => 'project_title'],
        'task' => ['task.id' => 'task_id', 'task.title' => 'task_title', 'task.estimatedMinutes' => 'task_estimated_minutes'],
    ];

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

    /** @return list<TimeEntry> entries the scope may see, newest first */
    public function findVisible(TimeScope $scope, TimeEntryFilter $filter, int $limit = 1000): array
    {
        $builder = $this->createQueryBuilder('entry')
            ->innerJoin('entry.user', 'user')->addSelect('user')
            ->innerJoin('entry.project', 'project')->addSelect('project')
            ->leftJoin('entry.task', 'task')->addSelect('task')
            ->orderBy('entry.workDate', 'DESC')->addOrderBy('entry.id', 'DESC')
            ->setMaxResults($limit);
        $this->restrict($builder, $scope, $filter);

        return $builder->getQuery()->getResult();
    }

    /**
     * Minutes summed in the database per requested dimension, split by approval status and billable flag (and cost
     * currency when costs are requested), so callers can fold the parts without loading individual entries.
     *
     * @param list<string> $dimensions subset of employee, project, task
     * @return list<array<string, mixed>>
     */
    public function aggregate(TimeScope $scope, TimeEntryFilter $filter, array $dimensions, bool $withCost): array
    {
        $builder = $this->createQueryBuilder('entry')
            ->select('entry.approvalStatus AS approval_status', 'entry.billable AS billable', 'SUM(entry.durationMinutes) AS minutes')
            ->innerJoin('entry.user', 'user')
            ->innerJoin('entry.project', 'project')
            ->leftJoin('entry.task', 'task')
            ->groupBy('entry.approvalStatus')->addGroupBy('entry.billable');
        foreach ($dimensions as $dimension) {
            foreach (self::DIMENSIONS[$dimension] ?? throw new \InvalidArgumentException('Unknown report dimension.') as $path => $alias) {
                $builder->addSelect(sprintf('%s AS %s', $path, $alias))->addGroupBy($path);
            }
        }
        if ($withCost) {
            $builder->addSelect('entry.costCurrency AS cost_currency', 'SUM(entry.costMinor) AS cost_minor')->addGroupBy('entry.costCurrency');
        }
        $this->restrict($builder, $scope, $filter);

        return $builder->getQuery()->getArrayResult();
    }

    /**
     * Non-rejected minutes per user and work date, for the weekly workload view.
     *
     * @return list<array{employee_id: int, employee_email: string, work_date: \DateTimeImmutable, minutes: int|string}>
     */
    public function dailyMinutesByUser(TimeScope $scope, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $builder = $this->createQueryBuilder('entry')
            ->select('user.id AS employee_id', 'user.email AS employee_email', 'entry.workDate AS work_date', 'SUM(entry.durationMinutes) AS minutes')
            ->innerJoin('entry.user', 'user')
            ->innerJoin('entry.project', 'project')
            ->andWhere('entry.approvalStatus <> :rejected')->setParameter('rejected', 'rejected')
            ->groupBy('user.id')->addGroupBy('user.email')->addGroupBy('entry.workDate');
        $this->restrict($builder, $scope, new TimeEntryFilter($from, $to));

        return $builder->getQuery()->getArrayResult();
    }

    /**
     * Logged (pending and approved) and approved minutes per task. Rejected time is not counted against an estimate.
     *
     * @param list<int> $taskIds
     * @return array<int, array{logged: int, approved: int}>
     */
    public function minutesByTask(array $taskIds): array
    {
        $taskIds = array_values(array_filter($taskIds, static fn (int $id): bool => $id > 0));
        if ($taskIds === []) {
            return [];
        }
        $rows = $this->createQueryBuilder('entry')
            ->select('task.id AS task_id', 'entry.approvalStatus AS approval_status', 'SUM(entry.durationMinutes) AS minutes')
            ->innerJoin('entry.task', 'task')
            ->andWhere('task.id IN (:ids)')->setParameter('ids', $taskIds)
            ->andWhere('entry.approvalStatus <> :rejected')->setParameter('rejected', 'rejected')
            ->groupBy('task.id')->addGroupBy('entry.approvalStatus')
            ->getQuery()->getArrayResult();
        $totals = [];
        foreach ($rows as $row) {
            $id = (int) $row['task_id'];
            $totals[$id] ??= ['logged' => 0, 'approved' => 0];
            $totals[$id]['logged'] += (int) $row['minutes'];
            if ($row['approval_status'] === 'approved') {
                $totals[$id]['approved'] += (int) $row['minutes'];
            }
        }

        return $totals;
    }

    /** @return list<int> ids of users with any time entry on a work date in the inclusive range */
    public function userIdsWithTimeBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return array_map('intval', $this->createQueryBuilder('entry')
            ->select('DISTINCT IDENTITY(entry.user)')
            ->andWhere('entry.workDate >= :from')->setParameter('from', $from, Types::DATE_IMMUTABLE)
            ->andWhere('entry.workDate <= :to')->setParameter('to', $to, Types::DATE_IMMUTABLE)
            ->getQuery()->getSingleColumnResult());
    }

    public function hasTimeOn(User $user, \DateTimeImmutable $day): bool
    {
        return (int) $this->createQueryBuilder('entry')
            ->select('COUNT(entry.id)')
            ->andWhere('entry.user = :user')->setParameter('user', $user)
            ->andWhere('entry.workDate = :day')->setParameter('day', $day, Types::DATE_IMMUTABLE)
            ->getQuery()->getSingleScalarResult() > 0;
    }

    /** Applies the visibility scope and the filters; expects the aliases entry, user and project. */
    private function restrict(QueryBuilder $builder, TimeScope $scope, TimeEntryFilter $filter): void
    {
        if (!$scope->seesEverything()) {
            if ($scope->managedProjectIds() === []) {
                $builder->andWhere('user.id = :scopeUser');
            } else {
                $builder->andWhere('user.id = :scopeUser OR project.id IN (:scopeProjects)')->setParameter('scopeProjects', $scope->managedProjectIds());
            }
            $builder->setParameter('scopeUser', $scope->userId());
        }
        if ($filter->from !== null) {
            $builder->andWhere('entry.workDate >= :from')->setParameter('from', $filter->from, Types::DATE_IMMUTABLE);
        }
        if ($filter->to !== null) {
            $builder->andWhere('entry.workDate <= :to')->setParameter('to', $filter->to, Types::DATE_IMMUTABLE);
        }
        if ($filter->projectId !== null) {
            $builder->andWhere('project.id = :projectId')->setParameter('projectId', $filter->projectId);
        }
        if ($filter->taskId !== null) {
            $builder->andWhere('IDENTITY(entry.task) = :taskId')->setParameter('taskId', $filter->taskId);
        }
        if ($filter->userId !== null) {
            $builder->andWhere('user.id = :userId')->setParameter('userId', $filter->userId);
        }
        if ($filter->status !== null) {
            $builder->andWhere('entry.approvalStatus = :status')->setParameter('status', $filter->status);
        }
        if ($filter->billable !== null) {
            $builder->andWhere('entry.billable = :billable')->setParameter('billable', $filter->billable);
        }
    }
}
