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

    /** @return list<PayablePayment> vendor payments in the currency within the date range, newest first */
    public function findInPeriod(string $currency, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('payment')->join('payment.bill', 'bill')->addSelect('bill')
            ->andWhere('bill.currency = :currency AND payment.paidAt >= :from AND payment.paidAt <= :to')
            ->setParameter('currency', $currency)->setParameter('from', $from, \Doctrine\DBAL\Types\Types::DATE_IMMUTABLE)->setParameter('to', $to, \Doctrine\DBAL\Types\Types::DATE_IMMUTABLE)
            ->orderBy('payment.paidAt', 'DESC')->addOrderBy('payment.id', 'DESC')->getQuery()->getResult();
    }
}
