<?php

namespace App\Repository;

use App\Entity\PurchaseOrder;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<PurchaseOrder> */
class PurchaseOrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, PurchaseOrder::class); }

    /** @return list<PurchaseOrder> */
    public function findRecent(): array { return $this->findBy([], ['orderedAt' => 'DESC', 'id' => 'DESC']); }
}