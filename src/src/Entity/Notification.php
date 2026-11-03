<?php

namespace App\Entity;

use App\Repository\NotificationRepository;
use Doctrine\ORM\Mapping as ORM;

/** In-app notification. The (user, dedupe key) pair is unique so repeated scheduler runs never duplicate a reminder. */
#[ORM\Entity(repositoryClass: NotificationRepository::class)]
#[ORM\Table(name: 'notification')]
#[ORM\UniqueConstraint(name: 'uniq_notification_dedupe', columns: ['user_id', 'dedupe_key'])]
#[ORM\Index(columns: ['user_id', 'read_at', 'created_at'], name: 'idx_notification_inbox')]
class Notification
{
    public const DUE_SOON = 'due_soon';
    public const OVERDUE = 'overdue';
    public const ESCALATION = 'escalation';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 16)]
    private string $kind;

    #[ORM\Column(length: 16)]
    private string $subjectType;

    #[ORM\Column]
    private int $subjectId;

    /** Project to open from the notification (the task's project, or the project itself). */
    #[ORM\Column(nullable: true)]
    private ?int $projectId;

    #[ORM\Column(length: 255)]
    private string $message;

    #[ORM\Column(length: 120)]
    private string $dedupeKey;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

    public function __construct(User $user, string $kind, string $subjectType, int $subjectId, ?int $projectId, string $message, string $dedupeKey)
    {
        $this->user = $user;
        $this->kind = $kind;
        $this->subjectType = $subjectType;
        $this->subjectId = $subjectId;
        $this->projectId = $projectId;
        $this->message = mb_substr($message, 0, 255);
        $this->dedupeKey = $dedupeKey;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getKind(): string { return $this->kind; }
    public function getSubjectType(): string { return $this->subjectType; }
    public function getSubjectId(): int { return $this->subjectId; }
    public function getProjectId(): ?int { return $this->projectId; }
    public function getMessage(): string { return $this->message; }
    public function getDedupeKey(): string { return $this->dedupeKey; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getReadAt(): ?\DateTimeImmutable { return $this->readAt; }
    public function markRead(): static { $this->readAt ??= new \DateTimeImmutable(); return $this; }
}
