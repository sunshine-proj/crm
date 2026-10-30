<?php

namespace App\Entity;

use App\Repository\FiscalPeriodRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FiscalPeriodRepository::class)]
#[ORM\Table(name: 'fiscal_period')]
#[ORM\UniqueConstraint(name: 'UNIQ_FISCAL_PERIOD_NAME', columns: ['name'])]
class FiscalPeriod
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 80)]
    private string $name;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $startsOn;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $endsOn;

    #[ORM\Column(options: ['default' => false])]
    private bool $closed = false;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $closedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $closeReason = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $reopenedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $reopenedAt = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $reopenReason = null;

    public function __construct(string $name, \DateTimeImmutable $startsOn, \DateTimeImmutable $endsOn)
    {
        $this->name = $name;
        $this->startsOn = $startsOn;
        $this->endsOn = $endsOn;
    }

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function getStartsOn(): \DateTimeImmutable { return $this->startsOn; }
    public function getEndsOn(): \DateTimeImmutable { return $this->endsOn; }
    public function isClosed(): bool { return $this->closed; }
    public function contains(\DateTimeImmutable $date): bool { return $date >= $this->startsOn && $date <= $this->endsOn; }
    public function getClosedBy(): ?string { return $this->closedBy; }
    public function getClosedAt(): ?\DateTimeImmutable { return $this->closedAt; }
    public function getCloseReason(): ?string { return $this->closeReason; }
    public function getReopenedBy(): ?string { return $this->reopenedBy; }
    public function getReopenedAt(): ?\DateTimeImmutable { return $this->reopenedAt; }
    public function getReopenReason(): ?string { return $this->reopenReason; }

    public function close(string $actor, string $reason, \DateTimeImmutable $at): static
    {
        if ($this->closed) {
            throw new \DomainException('Fiscal period is already closed.');
        }
        $this->closed = true;
        $this->closedBy = $actor;
        $this->closedAt = $at;
        $this->closeReason = $reason;
        return $this;
    }

    public function reopen(string $actor, string $reason, \DateTimeImmutable $at): static
    {
        if (!$this->closed) {
            throw new \DomainException('Only a closed fiscal period can be reopened.');
        }
        $this->closed = false;
        $this->reopenedBy = $actor;
        $this->reopenedAt = $at;
        $this->reopenReason = $reason;
        return $this;
    }
}