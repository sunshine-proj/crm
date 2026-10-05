<?php

namespace App\Repository;

use App\Entity\PayablePayment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<PayablePayment> */
class PayablePaymentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PayablePayment::class);
    }

    public function sumMinorForBill(int $billId): int
    {
        return (int) $this->createQueryBuilder('payment')
            ->select('COALESCE(SUM(payment.amountMinor), 0)')
            ->andWhere('payment.bill = :billId')
            ->setParameter('billId', $billId)
            ->getQuery()
            ->getSingleScalarResult();
    }
}