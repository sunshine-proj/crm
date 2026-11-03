<?php

namespace App\Repository;

use App\Entity\Deal;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Deal> */
class DealRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, Deal::class); }

    /** @return list<Deal> */
    public function search(?int $clientId, ?string $stage, ?int $ownerId, ?string $term): array
    {
        $query = $this->createQueryBuilder('deal')
            ->leftJoin('deal.client', 'client')->addSelect('client')
            ->leftJoin('deal.owner', 'owner')->addSelect('owner')
            ->orderBy('deal.updatedAt', 'DESC')->addOrderBy('deal.id', 'DESC');
        if ($clientId !== null) $query->andWhere('client.id = :client')->setParameter('client', $clientId);
        if ($stage !== null) $query->andWhere('deal.stage = :stage')->setParameter('stage', $stage);
        if ($ownerId !== null) $query->andWhere('owner.id = :owner')->setParameter('owner', $ownerId);
        if ($term !== null && $term !== '') {
            $query->andWhere('LOWER(deal.title) LIKE :term OR LOWER(client.title) LIKE :term')
                ->setParameter('term', '%'.addcslashes(mb_strtolower($term), '%_\\').'%');
        }

        return $query->getQuery()->getResult();
    }
}
