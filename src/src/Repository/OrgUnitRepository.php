<?php

namespace App\Repository;

use App\Entity\OrgUnit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<OrgUnit> */
class OrgUnitRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, OrgUnit::class); }

    /** @return list<OrgUnit> every unit, archived included, in a stable order */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('unit')->orderBy('unit.name', 'ASC')->addOrderBy('unit.id', 'ASC')->getQuery()->getResult();
    }

    public function hasChildren(OrgUnit $unit, bool $activeOnly = false): bool
    {
        $query = $this->createQueryBuilder('unit')->select('COUNT(unit.id)')->andWhere('unit.parent = :parent')->setParameter('parent', $unit);
        if ($activeOnly) {
            $query->andWhere('unit.archivedAt IS NULL');
        }

        return (int) $query->getQuery()->getSingleScalarResult() > 0;
    }
}
