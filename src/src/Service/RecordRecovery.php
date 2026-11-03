<?php

namespace App\Service;

use App\Entity\RecordRevision;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Restores a tracked record to the state captured by a revision: rolls an existing record back, or recreates
 * a deleted one (as a new row with a new id). Restoring is itself versioned by the revision listener.
 */
final class RecordRecovery
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TrackedRecords $trackedRecords,
        private readonly RecordSnapshotter $snapshotter,
    ) {
    }

    /**
     * @return array{entity: object, type: string, recreated: bool}
     * @throws \DomainException when the revision cannot be applied
     */
    public function restore(RecordRevision $revision): array
    {
        $class = $this->trackedRecords->classFor($revision->getEntityType());
        if ($class === null) {
            throw new \DomainException('This record type cannot be restored.');
        }

        return $this->entityManager->wrapInTransaction(function () use ($revision, $class): array {
            $entity = $this->entityManager->find($class, $revision->getEntityId());
            if ($entity !== null) {
                $this->snapshotter->apply($entity, $revision->getSnapshot(), ['createdAt']);
                $this->entityManager->flush();

                return ['entity' => $entity, 'type' => $revision->getEntityType(), 'recreated' => false];
            }
            if ($revision->getAction() !== RecordRevision::ACTION_DELETE) {
                throw new \DomainException('The record no longer exists; restore from its delete revision to recreate it.');
            }

            $entity = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
            $this->snapshotter->apply($entity, $revision->getSnapshot());
            $this->entityManager->persist($entity);
            $this->entityManager->flush();

            return ['entity' => $entity, 'type' => $revision->getEntityType(), 'recreated' => true];
        });
    }
}
