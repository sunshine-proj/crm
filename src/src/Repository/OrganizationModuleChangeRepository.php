<?php

namespace App\Repository;

use App\Entity\OrganizationModuleChange;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<OrganizationModuleChange> */
class OrganizationModuleChangeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, OrganizationModuleChange::class); }

    /** @return list<OrganizationModuleChange> newest first */
    public function recent(int $limit = 100): array
    {
        return $this->createQueryBuilder('change')->orderBy('change.changedAt', 'DESC')->addOrderBy('change.id', 'DESC')->setMaxResults($limit)->getQuery()->getResult();
    }
}
