<?php

namespace App\EventListener;

use App\Entity\RecordRevision;
use App\Entity\User;
use App\Service\RecordSnapshotter;
use App\Service\TrackedRecords;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Writes an append-only RecordRevision for every create, update and delete of a tracked business record,
 * in the same request as the change. Entity ids of new rows are only known after the flush, so revisions are
 * persisted in a follow-up flush.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class RecordRevisionListener
{
    /** @var list<array{entity: object, type: string, id: int|null, action: string, snapshot: array<string, mixed>|null, changes: array<string, array{0: mixed, 1: mixed}>|null}> */
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
        $unitOfWork = $args->getObjectManager()->getUnitOfWork();

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
        foreach ($unitOfWork->getScheduledEntityDeletions() as $entity) {
            if (($type = $this->trackedRecords->typeFor($entity)) !== null) {
                $this->pending[] = ['entity' => $entity, 'type' => $type, 'id' => $this->idOf($unitOfWork, $entity), 'action' => RecordRevision::ACTION_DELETE, 'snapshot' => $this->snapshotter->snapshot($entity), 'changes' => null];
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
                $id = $item['id'] ?? $this->idOf($unitOfWork, $item['entity']);
                if ($id === null) {
                    continue;
                }
                $snapshot = $item['snapshot'] ?? $this->snapshotter->snapshot($item['entity']);
                $entityManager->persist(new RecordRevision($item['type'], $id, $item['action'], $snapshot, $item['changes'], $actor));
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
