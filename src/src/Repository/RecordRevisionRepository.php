<?php

namespace App\Repository;

use App\Entity\RecordRevision;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class RecordRevisionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RecordRevision::class);
    }

    /** @return list<RecordRevision> */
    public function search(?string $entityType, ?int $entityId, ?string $action, int $limit): array
    {
        $criteria = array_filter(
            ['entityType' => $entityType, 'entityId' => $entityId, 'action' => $action],
            static fn (mixed $value): bool => $value !== null,
        );

        return $this->findBy($criteria, ['id' => 'DESC'], $limit);
    }
}
