<?php

namespace App\Repository;

use App\Entity\ExpenseReimbursement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ExpenseReimbursementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExpenseReimbursement::class);
    }

    public function sumMinorForExpense(int $expenseId): int
    {
        return (int) $this->createQueryBuilder('reimbursement')
            ->select('COALESCE(SUM(reimbursement.amountMinor), 0)')
            ->andWhere('IDENTITY(reimbursement.expense) = :expenseId')
            ->setParameter('expenseId', $expenseId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return list<ExpenseReimbursement> */
    public function findForExpense(int $expenseId): array
    {
        return $this->createQueryBuilder('reimbursement')
            ->andWhere('IDENTITY(reimbursement.expense) = :expenseId')
            ->setParameter('expenseId', $expenseId)
            ->orderBy('reimbursement.paidAt', 'DESC')
            ->addOrderBy('reimbursement.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
