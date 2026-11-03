<?php

namespace App\Repository;

use App\Entity\PurchaseOrderApproval;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<PurchaseOrderApproval> */
class PurchaseOrderApprovalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, PurchaseOrderApproval::class); }

    /** @return list<PurchaseOrderApproval> */
    public function forOrder(int $orderId): array
    {
        return $this->createQueryBuilder('a')->leftJoin('a.actor', 'actor')->addSelect('actor')
            ->andWhere('IDENTITY(a.purchaseOrder) = :id')->setParameter('id', $orderId)
            ->orderBy('a.createdAt', 'ASC')->addOrderBy('a.id', 'ASC')->getQuery()->getResult();
    }
}
