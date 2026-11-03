<?php

namespace App\Entity;

use App\Repository\TimeEntryEventRepository;
use Doctrine\ORM\Mapping as ORM;

/** Append-only history of approval decisions and reopenings of a time entry. */
#[ORM\Entity(repositoryClass: TimeEntryEventRepository::class)]
#[ORM\Table(name: 'time_entry_event')]
#[ORM\Index(columns: ['time_entry_id', 'created_at'], name: 'idx_time_entry_event_history')]
#[ORM\Index(columns: ['actor_id'], name: 'idx_time_entry_event_actor')]
class TimeEntryEvent
{
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    public const REOPENED = 'reopened';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: TimeEntry::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private TimeEntry $timeEntry;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $actor;

    #[ORM\Column(length: 16)]
    private string $action;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $comment;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(TimeEntry $timeEntry, User $actor, string $action, ?string $comment)
    {
        $this->timeEntry = $timeEntry;
        $this->actor = $actor;
        $this->action = $action;
        $this->comment = $comment;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getTimeEntry(): TimeEntry { return $this->timeEntry; }
    public function getActor(): User { return $this->actor; }
    public function getAction(): string { return $this->action; }
    public function getComment(): ?string { return $this->comment; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
