<?php

namespace App\Repository;

use App\Entity\DealStageChange;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<DealStageChange> */
class DealStageChangeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, DealStageChange::class); }

    /** @return list<DealStageChange> */
    public function findForDeal(int $dealId): array
    {
        return $this->createQueryBuilder('change')
            ->andWhere('IDENTITY(change.deal) = :id')->setParameter('id', $dealId)
            ->orderBy('change.createdAt', 'ASC')->addOrderBy('change.id', 'ASC')
            ->getQuery()->getResult();
    }
}
