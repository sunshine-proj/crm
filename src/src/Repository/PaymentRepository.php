<?php

namespace App\Repository;

use App\Entity\Payment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Payment> */
class PaymentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Payment::class);
    }

    /** @return list<Payment> */
    public function findForInvoice(int $invoiceId): array
    {
        return $this->createQueryBuilder('payment')
            ->andWhere('payment.invoice = :invoiceId')
            ->setParameter('invoiceId', $invoiceId)
            ->orderBy('payment.paidAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function sumMinorForInvoice(int $invoiceId): int
    {
        return (int) $this->createQueryBuilder('payment')
            ->select('COALESCE(SUM(payment.amountMinor), 0)')
            ->andWhere('payment.invoice = :invoiceId')
            ->setParameter('invoiceId', $invoiceId)
            ->getQuery()
            ->getSingleScalarResult();
    }
}