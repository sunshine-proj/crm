<?php

namespace App\Repository;

use App\Entity\Note;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Note>
 */
class NoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Note::class);
    }

    /**
     * @return list<Note>
     */
    public function findByEntity(string $entityType, int $entityId): array
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.entityType = :entityType')
            ->andWhere('n.entityId = :entityId')
            ->setParameter('entityType', $entityType)
            ->setParameter('entityId', $entityId)
            ->orderBy('n.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
