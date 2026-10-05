<?php

namespace App\Repository;

use App\Entity\SalesDocument;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<SalesDocument> */
class SalesDocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SalesDocument::class);
    }

    /** @return list<SalesDocument> */
    public function findByType(string $type): array
    {
        return $this->createQueryBuilder('document')
            ->andWhere('document.type = :type')
            ->setParameter('type', $type)
            ->orderBy('document.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findOrderFromQuote(int $quoteId): ?SalesDocument
    {
        return $this->createQueryBuilder('document')
            ->andWhere('document.type = :type')
            ->andWhere('document.sourceQuote = :quoteId')
            ->setParameter('type', 'sales_order')
            ->setParameter('quoteId', $quoteId)
            ->getQuery()
            ->getOneOrNullResult();
    }
}