<?php

namespace App\Repository;

use App\Entity\InventoryValuation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<InventoryValuation> */
class InventoryValuationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, InventoryValuation::class); }

    /** @return array{quantity: int, value: int, currency: string|null} valued quantity and value of one product/warehouse pool */
    public function pool(int $productId, int $warehouseId): array
    {
        $rows = $this->createQueryBuilder('v')
            ->select('v.currency AS currency, SUM(v.quantityDelta) AS quantity, SUM(v.valueMinor) AS value')
            ->andWhere('IDENTITY(v.product) = :product AND IDENTITY(v.warehouse) = :warehouse')
            ->setParameter('product', $productId)->setParameter('warehouse', $warehouseId)
            ->groupBy('v.currency')->getQuery()->getArrayResult();
        $quantity = 0;
        $value = 0;
        $currency = null;
        foreach ($rows as $row) {
            if ((int) $row['quantity'] !== 0 || (int) $row['value'] !== 0) {
                $currency = $row['currency'];
            }
            $quantity += (int) $row['quantity'];
            $value += (int) $row['value'];
        }

        return ['quantity' => $quantity, 'value' => $value, 'currency' => $currency];
    }

    /** @return list<array{product_id: int, warehouse_id: int, currency: string, quantity: int, value: int}> */
    public function pools(): array
    {
        $rows = $this->createQueryBuilder('v')
            ->select('IDENTITY(v.product) AS product_id, IDENTITY(v.warehouse) AS warehouse_id, v.currency AS currency, SUM(v.quantityDelta) AS quantity, SUM(v.valueMinor) AS value')
            ->groupBy('product_id, warehouse_id, v.currency')->getQuery()->getArrayResult();

        return array_map(static fn (array $row): array => ['product_id' => (int) $row['product_id'], 'warehouse_id' => (int) $row['warehouse_id'], 'currency' => $row['currency'], 'quantity' => (int) $row['quantity'], 'value' => (int) $row['value']], $rows);
    }

    /** @return list<InventoryValuation> */
    public function forSource(string $sourceType, int $sourceId, ?string $kind = null): array
    {
        $criteria = ['sourceType' => $sourceType, 'sourceId' => $sourceId];
        if ($kind !== null) {
            $criteria['kind'] = $kind;
        }

        return $this->findBy($criteria, ['id' => 'ASC']);
    }

    /** @param list<int> $sourceIds @return int total value of the given kind for the source records */
    public function sumValue(string $kind, array $sourceIds): int
    {
        if ($sourceIds === []) {
            return 0;
        }

        return (int) $this->createQueryBuilder('v')->select('COALESCE(SUM(v.valueMinor), 0)')
            ->andWhere('v.kind = :kind AND v.sourceId IN (:ids)')->setParameter('kind', $kind)->setParameter('ids', $sourceIds)
            ->getQuery()->getSingleScalarResult();
    }
}
