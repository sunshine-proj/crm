<?php

namespace App\Repository;

use App\Entity\Shipment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Shipment> */
class ShipmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Shipment::class);
    }

    /** @return list<Shipment> */
    public function findForOrder(int $orderId): array
    {
        return $this->createQueryBuilder('shipment')
            ->andWhere('shipment.salesOrder = :orderId')
            ->setParameter('orderId', $orderId)
            ->orderBy('shipment.shippedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}