<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** A group of steps decided in parallel. Rule `all`: every participant must agree; `any`: the first approval completes it. */
#[ORM\Entity]
#[ORM\Table(name: 'approval_stage')]
#[ORM\Index(columns: ['route_id', 'position'], name: 'idx_approval_stage_route')]
class ApprovalStage
{
    public const ALL = 'all';
    public const ANY = 'any';
    public const RULES = [self::ALL, self::ANY];
    public const WAITING = 'waiting';
    public const ACTIVE = 'active';
    public const COMPLETED = 'completed';
    public const REJECTED = 'rejected';
    public const RETURNED = 'returned';
    public const CANCELLED = 'cancelled';
    /** Completed in an earlier round and kept when the document was resubmitted with "continue". */
    public const CARRIED = 'carried';
    public const MAX_STEPS = 20;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ApprovalRoute::class, inversedBy: 'stages')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ApprovalRoute $route;

    #[ORM\Column]
    private int $position;

    #[ORM\Column(length: 8)]
    private string $rule;

    #[ORM\Column(length: 16)]
    private string $status = self::WAITING;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $activatedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    /** @var Collection<int, ApprovalStep> */
    #[ORM\OneToMany(targetEntity: ApprovalStep::class, mappedBy: 'stage', cascade: ['persist', 'refresh'])]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $steps;

    public function __construct(ApprovalRoute $route, int $position, string $rule)
    {
        if (!in_array($rule, self::RULES, true)) {
            throw new \InvalidArgumentException('A stage rule is "all" or "any".');
        }
        $this->route = $route;
        $this->position = $position;
        $this->rule = $rule;
        $this->steps = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getRoute(): ApprovalRoute { return $this->route; }
    public function getPosition(): int { return $this->position; }
    public function getRule(): string { return $this->rule; }
    public function getStatus(): string { return $this->status; }
    public function getActivatedAt(): ?\DateTimeImmutable { return $this->activatedAt; }
    public function getCompletedAt(): ?\DateTimeImmutable { return $this->completedAt; }

    /** @return list<ApprovalStep> */
    public function getSteps(): array
    {
        return array_values($this->steps->toArray());
    }

    /** Participants who decide the stage (consultations are advisory and not counted). */
    public function getStepCount(): int
    {
        return count(array_filter($this->getSteps(), static fn (ApprovalStep $step): bool => !$step->isConsultation()));
    }

    public function addStep(User $assignee, string $kind, ?string $position, ?string $unit, ?\DateTimeImmutable $dueOn, string $addedBy, ?ApprovalStep $parent = null, bool $openNow = false): ApprovalStep
    {
        if (!$parent && $this->getStepCount() >= self::MAX_STEPS) {
            throw new \InvalidArgumentException(sprintf('A stage has at most %d participants.', self::MAX_STEPS));
        }
        foreach ($this->getSteps() as $step) {
            if (!$parent && !$step->isConsultation() && $step->getAssignee()->getId() === $assignee->getId()) {
                throw new \InvalidArgumentException(sprintf('%s already takes part in this stage.', $assignee->getUserIdentifier()));
            }
        }
        $step = new ApprovalStep($this, $assignee, $kind, $position, $unit, $dueOn, $addedBy, $parent);
        if ($openNow || $this->status === self::ACTIVE) {
            $step->open();
        }
        $this->steps->add($step);

        return $step;
    }

    /** Copies a stage completed in an earlier round, keeping who decided it and when. */
    public function carryFrom(ApprovalStage $previous): void
    {
        foreach ($previous->getSteps() as $step) {
            if ($step->isConsultation() || !in_array($step->getStatus(), [ApprovalStep::APPROVED, ApprovalStep::CARRIED], true)) {
                continue;
            }
            $copy = new ApprovalStep($this, $step->getAssignee(), $step->getKind(), $step->getAssigneePosition(), $step->getAssigneeUnit(), $step->getDueOn(), $step->getAddedBy(), null);
            $copy->carry($step);
            $this->steps->add($copy);
        }
        $this->status = self::CARRIED;
        $this->completedAt = $previous->getCompletedAt();
    }

    public function activate(\DateTimeImmutable $now): void
    {
        $this->status = self::ACTIVE;
        $this->activatedAt = $now;
        foreach ($this->getSteps() as $step) {
            $step->open();
        }
    }

    /** With `all`, every deciding step approved; with `any`, at least one. */
    public function isSatisfied(): bool
    {
        $deciding = array_filter($this->getSteps(), static fn (ApprovalStep $step): bool => !$step->isConsultation());
        $approved = array_filter($deciding, static fn (ApprovalStep $step): bool => $step->getStatus() === ApprovalStep::APPROVED);

        return $this->rule === self::ANY ? $approved !== [] : count($approved) === count($deciding);
    }

    public function close(string $status, \DateTimeImmutable $now): void
    {
        $this->status = $status;
        $this->completedAt = $now;
        $this->cancelOpenSteps();
    }

    /** Steps nobody needs any more: skipped when the stage was decided without them, cancelled otherwise. */
    public function cancelOpenSteps(): void
    {
        foreach ($this->getSteps() as $step) {
            if ($step->isOpen() || $step->getStatus() === ApprovalStep::WAITING) {
                $step->cancel($this->status === self::COMPLETED && !$step->isConsultation() ? ApprovalStep::SKIPPED : ApprovalStep::CANCELLED);
            }
        }
    }
}
