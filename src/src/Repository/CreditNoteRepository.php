<?php

namespace App\Repository;

use App\Entity\CreditNote;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<CreditNote> */
class CreditNoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, CreditNote::class); }

    /** @return list<CreditNote> */
    public function forInvoice(int $invoiceId): array
    {
        return $this->findBy(['invoice' => $invoiceId], ['id' => 'ASC']);
    }

    public function sumTotalForInvoice(int $invoiceId): int
    {
        return (int) $this->createQueryBuilder('c')->select('COALESCE(SUM(c.totalMinor), 0)')
            ->andWhere('IDENTITY(c.invoice) = :id')->setParameter('id', $invoiceId)->getQuery()->getSingleScalarResult();
    }
}
