<?php

namespace App\Entity;

use App\Repository\PositionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A post within a unit. The head position of a unit makes its holder the superior of everyone else in the unit
 * and in sub-units without a head of their own. A unit has at most one active head position.
 */
#[ORM\Entity(repositoryClass: PositionRepository::class)]
#[ORM\Table(name: 'org_position')]
#[ORM\Index(columns: ['unit_id'], name: 'idx_org_position_unit')]
class Position
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: OrgUnit::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private OrgUnit $unit;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(options: ['default' => false])]
    private bool $isHead = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $archivedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(OrgUnit $unit, string $title, bool $isHead = false)
    {
        if ($unit->isArchived()) {
            throw new \DomainException('Positions cannot be added to an archived unit.');
        }
        $this->unit = $unit;
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
        $this->describe($title, $isHead);
    }

    public function getId(): ?int { return $this->id; }
    public function getUnit(): OrgUnit { return $this->unit; }
    public function getTitle(): string { return $this->title; }
    public function isHead(): bool { return $this->isHead; }
    public function getArchivedAt(): ?\DateTimeImmutable { return $this->archivedAt; }
    public function isArchived(): bool { return $this->archivedAt !== null; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function describe(string $title, bool $isHead): void
    {
        $title = trim($title);
        if ($title === '' || mb_strlen($title) > 255) {
            throw new \InvalidArgumentException('Position title is required (at most 255 characters).');
        }
        $this->title = $title;
        $this->isHead = $isHead;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function archive(): void
    {
        if ($this->archivedAt !== null) {
            throw new \DomainException('The position is already archived.');
        }
        $this->archivedAt = new \DateTimeImmutable();
        $this->updatedAt = $this->archivedAt;
    }
}
