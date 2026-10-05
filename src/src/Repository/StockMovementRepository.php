<?php

namespace App\Repository;

use App\Entity\StockMovement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<StockMovement> */
class StockMovementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, StockMovement::class); }

    public function onHand(int $productId, int $warehouseId): int
    {
        return (int) $this->createQueryBuilder('movement')
            ->select('COALESCE(SUM(movement.quantityDelta), 0)')
            ->andWhere('IDENTITY(movement.product) = :productId')
            ->andWhere('IDENTITY(movement.warehouse) = :warehouseId')
            ->setParameter('productId', $productId)
            ->setParameter('warehouseId', $warehouseId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function sumBySource(string $sourceType, int $sourceId, int $productId): int
    {
        return (int) $this->createQueryBuilder('movement')
            ->select('COALESCE(SUM(movement.quantityDelta), 0)')
            ->andWhere('movement.sourceType = :sourceType')
            ->andWhere('movement.sourceId = :sourceId')
            ->andWhere('IDENTITY(movement.product) = :productId')
            ->setParameter('sourceType', $sourceType)
            ->setParameter('sourceId', $sourceId)
            ->setParameter('productId', $productId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function hasSource(string $sourceType, int $sourceId): bool
    {
        return $this->createQueryBuilder('movement')
            ->select('COUNT(movement.id)')
            ->andWhere('movement.sourceType = :sourceType')
            ->andWhere('movement.sourceId = :sourceId')
            ->setParameter('sourceType', $sourceType)
            ->setParameter('sourceId', $sourceId)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /** @return list<StockMovement> */
    public function findRecent(int $limit = 300): array
    {
        return $this->createQueryBuilder('movement')
            ->leftJoin('movement.product', 'product')->addSelect('product')
            ->leftJoin('movement.warehouse', 'warehouse')->addSelect('warehouse')
            ->orderBy('movement.movedAt', 'DESC')->addOrderBy('movement.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** @return list<array{product_id: int, warehouse_id: int, quantity: int}> */
    public function findBalances(?int $warehouseId = null): array
    {
        $query = $this->createQueryBuilder('movement')
            ->select('IDENTITY(movement.product) AS product_id, IDENTITY(movement.warehouse) AS warehouse_id, SUM(movement.quantityDelta) AS quantity')
            ->groupBy('movement.product, movement.warehouse')
            ->having('SUM(movement.quantityDelta) <> 0')
            ->orderBy('warehouse_id', 'ASC')->addOrderBy('product_id', 'ASC');
        if ($warehouseId !== null) {
            $query->andWhere('IDENTITY(movement.warehouse) = :warehouseId')->setParameter('warehouseId', $warehouseId);
        }
        return $query->getQuery()->getArrayResult();
    }
}