<?php

namespace App\Repository;

use App\Entity\Expense;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Expense> */
class ExpenseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, Expense::class); }

    /** @return list<Expense> */
    public function findFiltered(?int $projectId = null, ?string $status = null): array
    {
        $query = $this->createQueryBuilder('expense')
            ->leftJoin('expense.project', 'project')->addSelect('project')
            ->leftJoin('expense.submitter', 'submitter')->addSelect('submitter')
            ->orderBy('expense.expenseDate', 'DESC')->addOrderBy('expense.id', 'DESC');
        if ($projectId !== null) $query->andWhere('IDENTITY(expense.project) = :projectId')->setParameter('projectId', $projectId);
        if ($status !== null) $query->andWhere('expense.status = :status')->setParameter('status', $status);
        return $query->getQuery()->getResult();
    }
}