<?php

namespace App\Entity;

use App\Repository\ApprovalActionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Append-only approval history: who did what, on whose behalf, with which comment, on which exact version. */
#[ORM\Entity(repositoryClass: ApprovalActionRepository::class)]
#[ORM\Table(name: 'approval_action')]
#[ORM\Index(columns: ['route_id', 'created_at'], name: 'idx_approval_action_route')]
class ApprovalAction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ApprovalRoute::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ApprovalRoute $route;

    #[ORM\ManyToOne(targetEntity: ApprovalStep::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ApprovalStep $step;

    #[ORM\Column(length: 180)]
    private string $actor;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $onBehalfOf;

    #[ORM\Column(length: 32)]
    private string $action;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment;

    #[ORM\Column]
    private int $versionNumber;

    #[ORM\Column(length: 64)]
    private string $versionSha256;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(ApprovalRoute $route, ?ApprovalStep $step, string $actor, ?string $onBehalfOf, string $action, ?string $comment)
    {
        $this->route = $route;
        $this->step = $step;
        $this->actor = $actor;
        $this->onBehalfOf = $onBehalfOf;
        $this->action = $action;
        $comment = $comment !== null ? trim($comment) : null;
        $this->comment = $comment === '' ? null : $comment;
        $this->versionNumber = $route->getVersionNumber();
        $this->versionSha256 = $route->getVersionSha256();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getRoute(): ApprovalRoute { return $this->route; }
    public function getStep(): ?ApprovalStep { return $this->step; }
    public function getActor(): string { return $this->actor; }
    public function getOnBehalfOf(): ?string { return $this->onBehalfOf; }
    public function getAction(): string { return $this->action; }
    public function getComment(): ?string { return $this->comment; }
    public function getVersionNumber(): int { return $this->versionNumber; }
    public function getVersionSha256(): string { return $this->versionSha256; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
