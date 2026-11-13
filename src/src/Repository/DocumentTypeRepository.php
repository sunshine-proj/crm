<?php

namespace App\Repository;

use App\Entity\DocumentType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<DocumentType> */
class DocumentTypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, DocumentType::class); }

    /** @return list<DocumentType> */
    public function findAllOrdered(bool $activeOnly = false): array
    {
        $query = $this->createQueryBuilder('type')->orderBy('type.name', 'ASC')->addOrderBy('type.id', 'ASC');
        if ($activeOnly) {
            $query->andWhere('type.active = true');
        }

        return $query->getQuery()->getResult();
    }
}
