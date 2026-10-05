<?php

namespace App\Entity;

use App\Repository\TimeEntryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TimeEntryRepository::class)]
#[ORM\Table(name: 'time_entry')]
#[ORM\Index(columns: ['project_id'], name: 'idx_time_entry_project')]
#[ORM\Index(columns: ['project_id', 'work_date'], name: 'idx_time_entry_project_date')]
#[ORM\Index(columns: ['task_id'], name: 'idx_time_entry_task')]
#[ORM\Index(columns: ['user_id'], name: 'idx_time_entry_user')]
class TimeEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Project $project;

    #[ORM\ManyToOne(targetEntity: Task::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Task $task;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $user;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $workDate;

    #[ORM\Column]
    private int $durationMinutes;

    #[ORM\Column]
    private bool $billable;

    #[ORM\Column(length: 255)]
    private string $description;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Project $project, ?Task $task, User $user, \DateTimeImmutable $workDate, int $durationMinutes, bool $billable, string $description)
    {
        if ($durationMinutes < 1 || trim($description) === '') {
            throw new \InvalidArgumentException('Time entry requires positive duration and a description.');
        }
        $this->project = $project;
        $this->task = $task;
        $this->user = $user;
        $this->workDate = $workDate;
        $this->durationMinutes = $durationMinutes;
        $this->billable = $billable;
        $this->description = trim($description);
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getProject(): Project { return $this->project; }
    public function getTask(): ?Task { return $this->task; }
    public function getUser(): User { return $this->user; }
    public function getWorkDate(): \DateTimeImmutable { return $this->workDate; }
    public function getDurationMinutes(): int { return $this->durationMinutes; }
    public function isBillable(): bool { return $this->billable; }
    public function getDescription(): string { return $this->description; }
}