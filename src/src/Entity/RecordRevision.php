<?php

namespace App\Entity;

use App\Repository\RecordRevisionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Append-only snapshot of a tracked business record after a create, update, or delete. */
#[ORM\Entity(repositoryClass: RecordRevisionRepository::class)]
#[ORM\Table(name: 'record_revision')]
#[ORM\Index(columns: ['entity_type', 'entity_id', 'id'], name: 'idx_record_revision_record')]
#[ORM\Index(columns: ['action', 'entity_type'], name: 'idx_record_revision_action')]
class RecordRevision
{
    public const ACTION_CREATE = 'create';
    public const ACTION_UPDATE = 'update';
    public const ACTION_DELETE = 'delete';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $entityType;

    #[ORM\Column]
    private int $entityId;

    #[ORM\Column(length: 10)]
    private string $action;

    /** @var array<string, mixed> Record state after the change (state at deletion for deletes). */
    #[ORM\Column(type: Types::JSON)]
    private array $snapshot;

    /** @var array<string, array{0: mixed, 1: mixed}>|null Field => [old, new] for updates. */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $changes;

    #[ORM\Column(length: 180)]
    private string $actor;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed> $snapshot
     * @param array<string, array{0: mixed, 1: mixed}>|null $changes
     */
    public function __construct(string $entityType, int $entityId, string $action, array $snapshot, ?array $changes, string $actor)
    {
        $this->entityType = $entityType;
        $this->entityId = $entityId;
        $this->action = $action;
        $this->snapshot = $snapshot;
        $this->changes = $changes;
        $this->actor = $actor;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getEntityType(): string { return $this->entityType; }
    public function getEntityId(): int { return $this->entityId; }
    public function getAction(): string { return $this->action; }
    /** @return array<string, mixed> */
    public function getSnapshot(): array { return $this->snapshot; }
    /** @return array<string, array{0: mixed, 1: mixed}>|null */
    public function getChanges(): ?array { return $this->changes; }
    public function getActor(): string { return $this->actor; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
