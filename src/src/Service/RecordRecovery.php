<?php

namespace App\Service;

use App\Entity\RecordRevision;
use App\Repository\RecordRevisionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Restores a tracked record to the state captured by a revision: rolls an existing record back, or recreates
 * a deleted one (as a new row with a new id). Restoring is itself versioned by the revision listener.
 *
 * Recreating a deleted record can also bring back the tracked records its deletion removed through database
 * cascades (for example a client's contacts and deals) and re-link the ones it detached (its projects).
 */
final class RecordRecovery
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TrackedRecords $trackedRecords,
        private readonly RecordSnapshotter $snapshotter,
        private readonly RecordRevisionRepository $revisions,
    ) {
    }

    /**
     * @return array{entity: object, type: string, recreated: bool, related: int}
     * @throws \DomainException when the revision cannot be applied
     */
    public function restore(RecordRevision $revision, bool $withRelated = true): array
    {
        $class = $this->trackedRecords->classFor($revision->getEntityType());
        if ($class === null) {
            throw new \DomainException('This record type cannot be restored.');
        }

        return $this->entityManager->wrapInTransaction(function () use ($revision, $class, $withRelated): array {
            $entity = $this->entityManager->find($class, $revision->getEntityId());
            if ($entity !== null) {
                $this->snapshotter->apply($entity, $revision->getSnapshot(), ['createdAt']);
                $this->entityManager->flush();

                return ['entity' => $entity, 'type' => $revision->getEntityType(), 'recreated' => false, 'related' => 0];
            }
            if ($revision->getAction() !== RecordRevision::ACTION_DELETE) {
                throw new \DomainException('The record no longer exists; restore from its delete revision to recreate it.');
            }

            $entity = $this->recreate($class, $revision);
            $related = $withRelated ? $this->restoreRelated($revision->getEntityType(), $revision->getEntityId(), $entity) : 0;

            return ['entity' => $entity, 'type' => $revision->getEntityType(), 'recreated' => true, 'related' => $related];
        });
    }

    /**
     * @param class-string $class
     * @param array<string, object> $references
     */
    private function recreate(string $class, RecordRevision $revision, array $references = []): object
    {
        $metadata = $this->entityManager->getClassMetadata($class);
        $entity = $metadata->getReflectionClass()->newInstanceWithoutConstructor();
        foreach ($metadata->getAssociationNames() as $name) {
            if ($metadata->isCollectionValuedAssociation($name)) {
                $metadata->setFieldValue($entity, $name, new ArrayCollection());
            }
        }
        $this->snapshotter->apply($entity, $revision->getSnapshot(), [], $references);
        $this->entityManager->persist($entity);
        $this->entityManager->flush();

        return $entity;
    }

    /** Brings back what the deletion of record ($type, $oldId) removed or detached; returns the number of records. */
    private function restoreRelated(string $type, int $oldId, object $replacement): int
    {
        $restored = 0;
        foreach ($this->revisions->findCausedBy($type, $oldId) as $dependent) {
            $class = $this->trackedRecords->classFor($dependent->getEntityType());
            if ($class === null) {
                continue;
            }
            $existing = $this->entityManager->find($class, $dependent->getEntityId());
            if ($dependent->getAction() === RecordRevision::ACTION_DELETE) {
                if ($existing !== null) {
                    continue;
                }
                $references = array_fill_keys($this->linksTo($class, $replacement, $dependent->getSnapshot(), $oldId), $replacement);
                $child = $this->recreate($class, $dependent, $references);
                $restored += 1 + $this->restoreRelated($dependent->getEntityType(), $dependent->getEntityId(), $child);
                continue;
            }
            if ($existing === null) {
                continue;
            }
            $before = array_map(static fn (array $change): mixed => $change[0], $dependent->getChanges() ?? []);
            $metadata = $this->entityManager->getClassMetadata($class);
            foreach ($this->linksTo($class, $replacement, $before, $oldId) as $name) {
                if ($metadata->getFieldValue($existing, $name) === null) {
                    $metadata->setFieldValue($existing, $name, $replacement);
                    ++$restored;
                }
            }
        }
        $this->entityManager->flush();

        return $restored;
    }

    /**
     * @param class-string $class
     * @param array<string, mixed> $state
     * @return list<string> associations of $class that pointed at the deleted record
     */
    private function linksTo(string $class, object $replacement, array $state, int $oldId): array
    {
        $names = [];
        foreach ($this->entityManager->getClassMetadata($class)->getAssociationMappings() as $name => $mapping) {
            if ($mapping->isToOneOwningSide() && is_a($replacement, $mapping->targetEntity) && isset($state[$name]) && (int) $state[$name] === $oldId) {
                $names[] = $name;
            }
        }

        return $names;
    }
}
