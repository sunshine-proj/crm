<?php

namespace App\Repository;

use App\Entity\ExpenseApproval;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ExpenseApproval> */
class ExpenseApprovalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, ExpenseApproval::class); }

    /** @return list<ExpenseApproval> */
    public function findForExpense(int $expenseId): array
    {
        return $this->createQueryBuilder('approval')
            ->leftJoin('approval.actor', 'actor')->addSelect('actor')
            ->andWhere('IDENTITY(approval.expense) = :expenseId')
            ->setParameter('expenseId', $expenseId)
            ->orderBy('approval.createdAt', 'ASC')
            ->getQuery()->getResult();
    }
}