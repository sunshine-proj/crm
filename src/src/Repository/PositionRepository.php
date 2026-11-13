<?php

namespace App\Repository;

use App\Entity\OrgUnit;
use App\Entity\Position;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Position> */
class PositionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, Position::class); }

    /** @return list<Position> */
    public function findAllOrdered(?int $unitId = null): array
    {
        $query = $this->createQueryBuilder('position')->orderBy('position.isHead', 'DESC')->addOrderBy('position.title', 'ASC')->addOrderBy('position.id', 'ASC');
        if ($unitId !== null) {
            $query->andWhere('IDENTITY(position.unit) = :unit')->setParameter('unit', $unitId);
        }

        return $query->getQuery()->getResult();
    }

    public function findActiveHead(OrgUnit $unit, ?Position $except = null): ?Position
    {
        $query = $this->createQueryBuilder('position')
            ->andWhere('position.unit = :unit')->setParameter('unit', $unit)
            ->andWhere('position.isHead = true')->andWhere('position.archivedAt IS NULL')
            ->setMaxResults(1);
        if ($except?->getId() !== null) {
            $query->andWhere('position.id <> :except')->setParameter('except', $except->getId());
        }

        return $query->getQuery()->getOneOrNullResult();
    }

    public function countForUnit(OrgUnit $unit): int
    {
        return (int) $this->createQueryBuilder('position')->select('COUNT(position.id)')
            ->andWhere('position.unit = :unit')->setParameter('unit', $unit)->getQuery()->getSingleScalarResult();
    }
}
