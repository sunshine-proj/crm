<?php

namespace App\EventListener;

use App\Entity\RecordRevision;
use App\Entity\User;
use App\Service\RecordSnapshotter;
use App\Service\TrackedRecords;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Writes an append-only RecordRevision for every create, update and delete of a tracked business record,
 * in the same request as the change. Entity ids of new rows are only known after the flush, so revisions are
 * persisted in a follow-up flush.
 *
 * Deleting a tracked record also removes or detaches other tracked records through database foreign keys
 * (ON DELETE CASCADE / SET NULL). Doctrine never sees those rows change, so they are looked up here and
 * versioned with a link to the deleted parent, which lets RecordRecovery bring them back together.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class RecordRevisionListener
{
    /** @var list<array{entity: object, type: string, id: int|null, action: string, snapshot: array<string, mixed>|null, changes: array<string, array{0: mixed, 1: mixed}>|null, cause?: array{0: string, 1: int}}> */
    private array $pending = [];
    private bool $recording = false;

    public function __construct(
        private readonly TrackedRecords $trackedRecords,
        private readonly RecordSnapshotter $snapshotter,
        private readonly Security $security,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        if ($this->recording) {
            return;
        }
        $this->pending = []; // anything left over belongs to a flush that failed and rolled back
        $entityManager = $args->getObjectManager();
        $unitOfWork = $entityManager->getUnitOfWork();

        foreach ($unitOfWork->getScheduledEntityInsertions() as $entity) {
            if (($type = $this->trackedRecords->typeFor($entity)) !== null) {
                $this->pending[] = ['entity' => $entity, 'type' => $type, 'id' => null, 'action' => RecordRevision::ACTION_CREATE, 'snapshot' => null, 'changes' => null];
            }
        }
        foreach ($unitOfWork->getScheduledEntityUpdates() as $entity) {
            if (($type = $this->trackedRecords->typeFor($entity)) === null) {
                continue;
            }
            $changes = [];
            foreach ($unitOfWork->getEntityChangeSet($entity) as $field => [$old, $new]) {
                $changes[$field] = [$this->scalar($old), $this->scalar($new)];
            }
            $this->pending[] = ['entity' => $entity, 'type' => $type, 'id' => $this->idOf($unitOfWork, $entity), 'action' => RecordRevision::ACTION_UPDATE, 'snapshot' => $this->snapshotter->snapshot($entity), 'changes' => $changes];
        }
        $deletions = $unitOfWork->getScheduledEntityDeletions();
        $handled = array_fill_keys(array_map(spl_object_id(...), $deletions), true);
        foreach ($deletions as $entity) {
            if (($type = $this->trackedRecords->typeFor($entity)) !== null) {
                $this->pending[] = ['entity' => $entity, 'type' => $type, 'id' => $this->idOf($unitOfWork, $entity), 'action' => RecordRevision::ACTION_DELETE, 'snapshot' => $this->snapshotter->snapshot($entity), 'changes' => null];
                $this->recordDatabaseCascades($entityManager, $entity, $type, $handled);
            }
        }
    }

    /**
     * Versions tracked records that the database will delete (CASCADE) or detach (SET NULL) with the parent.
     *
     * @param array<int, true> $handled object ids already versioned as deleted in this flush
     */
    private function recordDatabaseCascades(EntityManagerInterface $entityManager, object $parent, string $parentType, array &$handled): void
    {
        $parentId = $this->idOf($entityManager->getUnitOfWork(), $parent);
        if ($parentId === null) {
            return;
        }
        foreach ($this->trackedRecords->types() as $childType) {
            $class = $this->trackedRecords->classFor($childType);
            foreach ($entityManager->getClassMetadata($class)->getAssociationMappings() as $name => $mapping) {
                if (!$mapping->isToOneOwningSide() || !is_a($parent, $mapping->targetEntity)) {
                    continue;
                }
                $rule = strtoupper((string) ($mapping->joinColumns[0]->onDelete ?? ''));
                if ($rule !== 'CASCADE' && $rule !== 'SET NULL') {
                    continue;
                }
                foreach ($entityManager->getRepository($class)->findBy([$name => $parent]) as $child) {
                    if (isset($handled[spl_object_id($child)])) {
                        continue;
                    }
                    $childId = $this->idOf($entityManager->getUnitOfWork(), $child);
                    $snapshot = $this->snapshotter->snapshot($child);
                    if ($rule === 'CASCADE') {
                        $handled[spl_object_id($child)] = true;
                        $this->pending[] = ['entity' => $child, 'type' => $childType, 'id' => $childId, 'action' => RecordRevision::ACTION_DELETE, 'snapshot' => $snapshot, 'changes' => null, 'cause' => [$parentType, $parentId]];
                        $this->recordDatabaseCascades($entityManager, $child, $childType, $handled);
                        continue;
                    }
                    $snapshot[$name] = null;
                    $this->pending[] = ['entity' => $child, 'type' => $childType, 'id' => $childId, 'action' => RecordRevision::ACTION_UPDATE, 'snapshot' => $snapshot, 'changes' => [$name => [$parentId, null]], 'cause' => [$parentType, $parentId]];
                }
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->recording || $this->pending === []) {
            return;
        }
        $pending = $this->pending;
        $this->pending = [];
        $entityManager = $args->getObjectManager();
        $unitOfWork = $entityManager->getUnitOfWork();
        $actor = $this->actor();

        $this->recording = true;
        try {
            foreach ($pending as $item) {
                if (isset($item['cause'])) {
                    // The database has removed or detached this row; the managed copy still points at the
                    // deleted parent and would make the follow-up flush fail.
                    $entityManager->detach($item['entity']);
                }
                $id = $item['id'] ?? $this->idOf($unitOfWork, $item['entity']);
                if ($id === null) {
                    continue;
                }
                $snapshot = $item['snapshot'] ?? $this->snapshotter->snapshot($item['entity']);
                $entityManager->persist(new RecordRevision($item['type'], $id, $item['action'], $snapshot, $item['changes'], $actor, $item['cause'][0] ?? null, $item['cause'][1] ?? null));
            }
            $entityManager->flush();
        } finally {
            $this->recording = false;
        }
    }

    private function idOf(\Doctrine\ORM\UnitOfWork $unitOfWork, object $entity): ?int
    {
        $id = $unitOfWork->getSingleIdentifierValue($entity);

        return is_numeric($id) ? (int) $id : null;
    }

    private function scalar(mixed $value): mixed
    {
        return match (true) {
            $value instanceof \BackedEnum => $value->value,
            $value instanceof \DateTimeInterface => $value->format(\DateTimeInterface::ATOM),
            is_object($value) && method_exists($value, 'getId') => $value->getId(),
            is_scalar($value) || $value === null || is_array($value) => $value,
            default => null,
        };
    }

    private function actor(): string
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user->getUserIdentifier() : 'system';
    }
}
