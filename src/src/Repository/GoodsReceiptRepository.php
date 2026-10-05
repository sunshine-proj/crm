<?php

namespace App\Repository;

use App\Entity\GoodsReceipt;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<GoodsReceipt> */
class GoodsReceiptRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, GoodsReceipt::class); }

    /** @return list<GoodsReceipt> */
    public function findForPurchaseOrder(int $purchaseOrderId): array
    {
        return $this->findBy(['purchaseOrder' => $purchaseOrderId], ['receivedAt' => 'ASC', 'id' => 'ASC']);
    }
}