<?php

namespace App\Repository;

use App\Entity\InventoryReturn;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<InventoryReturn> */
class InventoryReturnRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, InventoryReturn::class); }

    /** @return list<InventoryReturn> */
    public function findForSource(string $returnType, string $sourceType, int $sourceId): array
    {
        return $this->findBy(['returnType' => $returnType, 'sourceType' => $sourceType, 'sourceId' => $sourceId]);
    }
}