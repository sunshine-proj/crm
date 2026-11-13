<?php

namespace App\Entity;

use App\Repository\EmploymentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A user holding a position for a date range. A user has at most one primary employment at a time, which decides
 * their unit and direct superior; further (secondary or acting) employments add responsibilities, for example an
 * acting head. `reportsTo` overrides the superior derived from the structure.
 */
#[ORM\Entity(repositoryClass: EmploymentRepository::class)]
#[ORM\Table(name: 'employment')]
#[ORM\Index(columns: ['user_id'], name: 'idx_employment_user')]
#[ORM\Index(columns: ['position_id'], name: 'idx_employment_position')]
#[ORM\Index(columns: ['reports_to_id'], name: 'idx_employment_reports_to')]
class Employment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Position::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Position $position;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?User $reportsTo = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $isPrimary = true;

    #[ORM\Column(options: ['default' => false])]
    private bool $isActing = false;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $startsOn;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endsOn = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $user, Position $position, \DateTimeImmutable $startsOn, bool $isPrimary = true, bool $isActing = false)
    {
        if ($position->isArchived() || $position->getUnit()->isArchived()) {
            throw new \DomainException('Employees cannot be assigned to an archived position or unit.');
        }
        $this->user = $user;
        $this->position = $position;
        $this->startsOn = $startsOn->setTime(0, 0);
        $this->isPrimary = $isPrimary;
        $this->isActing = $isActing;
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getPosition(): Position { return $this->position; }
    public function getReportsTo(): ?User { return $this->reportsTo; }
    public function isPrimary(): bool { return $this->isPrimary; }
    public function isActing(): bool { return $this->isActing; }
    public function getStartsOn(): \DateTimeImmutable { return $this->startsOn; }
    public function getEndsOn(): ?\DateTimeImmutable { return $this->endsOn; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function isActiveOn(\DateTimeImmutable $date): bool
    {
        $day = $date->format('Y-m-d');

        return $this->startsOn->format('Y-m-d') <= $day && ($this->endsOn === null || $this->endsOn->format('Y-m-d') >= $day);
    }

    public function overlaps(\DateTimeImmutable $startsOn, ?\DateTimeImmutable $endsOn): bool
    {
        return ($endsOn === null || $this->startsOn->format('Y-m-d') <= $endsOn->format('Y-m-d'))
            && ($this->endsOn === null || $this->endsOn->format('Y-m-d') >= $startsOn->format('Y-m-d'));
    }

    public function endOn(?\DateTimeImmutable $endsOn): void
    {
        if ($endsOn !== null && $endsOn->format('Y-m-d') < $this->startsOn->format('Y-m-d')) {
            throw new \InvalidArgumentException('An employment cannot end before it starts.');
        }
        $this->endsOn = $endsOn?->setTime(0, 0);
        $this->touch();
    }

    public function reportTo(?User $superior): void
    {
        if ($superior === $this->user) {
            throw new \InvalidArgumentException('An employee cannot report to themselves.');
        }
        $this->reportsTo = $superior;
        $this->touch();
    }

    public function classify(bool $isPrimary, bool $isActing): void
    {
        $this->isPrimary = $isPrimary;
        $this->isActing = $isActing;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
