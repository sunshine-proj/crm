<?php

namespace App\Entity;

use App\Repository\DealStageChangeRepository;
use Doctrine\ORM\Mapping as ORM;

/** Append-only record of every stage a deal entered, including its creation. */
#[ORM\Entity(repositoryClass: DealStageChangeRepository::class)]
#[ORM\Table(name: 'deal_stage_change')]
#[ORM\Index(columns: ['deal_id', 'created_at'], name: 'idx_deal_stage_change_history')]
class DealStageChange
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Deal::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Deal $deal;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $fromStage;

    #[ORM\Column(length: 16)]
    private string $toStage;

    #[ORM\Column(length: 180)]
    private string $actor;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $note;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Deal $deal, ?string $fromStage, string $toStage, string $actor, ?string $note)
    {
        $this->deal = $deal;
        $this->fromStage = $fromStage;
        $this->toStage = $toStage;
        $this->actor = $actor;
        $this->note = $note;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getFromStage(): ?string { return $this->fromStage; }
    public function getToStage(): string { return $this->toStage; }
    public function getActor(): string { return $this->actor; }
    public function getNote(): ?string { return $this->note; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
