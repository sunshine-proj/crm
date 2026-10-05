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
    public function close(): static { $this->closed = true; return $this; }
}