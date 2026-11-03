<?php

namespace App\Entity;

use App\Repository\ReminderPreferenceRepository;
use Doctrine\ORM\Mapping as ORM;

/** Per-user reminder timing. Users without a row use the defaults below. */
#[ORM\Entity(repositoryClass: ReminderPreferenceRepository::class)]
#[ORM\Table(name: 'reminder_preference')]
#[ORM\UniqueConstraint(name: 'uniq_reminder_preference_user', columns: ['user_id'])]
class ReminderPreference
{
    public const DEFAULT_TASK_LEAD_DAYS = 1;
    public const DEFAULT_PROJECT_LEAD_DAYS = 3;
    public const DEFAULT_ESCALATE_AFTER_DAYS = 3;
    public const MAX_DAYS = 60;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(options: ['default' => true])]
    private bool $enabled = true;

    #[ORM\Column(type: 'smallint', options: ['default' => 1])]
    private int $taskLeadDays = self::DEFAULT_TASK_LEAD_DAYS;

    #[ORM\Column(type: 'smallint', options: ['default' => 3])]
    private int $projectLeadDays = self::DEFAULT_PROJECT_LEAD_DAYS;

    /** Days overdue before the responsible manager is told; 0 disables escalation of this user's overdue work. */
    #[ORM\Column(type: 'smallint', options: ['default' => 3])]
    private int $escalateAfterDays = self::DEFAULT_ESCALATE_AFTER_DAYS;

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function isEnabled(): bool { return $this->enabled; }
    public function getTaskLeadDays(): int { return $this->taskLeadDays; }
    public function getProjectLeadDays(): int { return $this->projectLeadDays; }
    public function getEscalateAfterDays(): int { return $this->escalateAfterDays; }

    public function configure(bool $enabled, int $taskLeadDays, int $projectLeadDays, int $escalateAfterDays): static
    {
        foreach ([$taskLeadDays, $projectLeadDays, $escalateAfterDays] as $days) {
            if ($days < 0 || $days > self::MAX_DAYS) {
                throw new \InvalidArgumentException(sprintf('Reminder timing must be between 0 and %d days.', self::MAX_DAYS));
            }
        }
        $this->enabled = $enabled;
        $this->taskLeadDays = $taskLeadDays;
        $this->projectLeadDays = $projectLeadDays;
        $this->escalateAfterDays = $escalateAfterDays;

        return $this;
    }
}
