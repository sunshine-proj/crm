<?php

namespace App\Entity;

use App\Repository\ApprovalRouteRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One approval round of a document: stages run one after another, the steps of a stage run in parallel. The route
 * is pinned to the exact version (number and SHA-256) that was submitted; a document in approval cannot change.
 * All state changes go through this aggregate so the stage/route outcome is decided in one place.
 */
#[ORM\Entity(repositoryClass: ApprovalRouteRepository::class)]
#[ORM\Table(name: 'approval_route')]
#[ORM\UniqueConstraint(name: 'uniq_approval_route_round', columns: ['document_id', 'round'])]
class ApprovalRoute
{
    public const ACTIVE = 'active';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    public const RETURNED = 'returned';
    public const WITHDRAWN = 'withdrawn';
    public const MAX_STAGES = 10;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Document::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Document $document;

    #[ORM\Column]
    private int $round;

    #[ORM\Column(length: 16)]
    private string $status = self::ACTIVE;

    #[ORM\Column]
    private int $versionNumber;

    #[ORM\Column(length: 64)]
    private string $versionSha256;

    #[ORM\Column(length: 180)]
    private string $createdBy;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    /** @var Collection<int, ApprovalStage> */
    #[ORM\OneToMany(targetEntity: ApprovalStage::class, mappedBy: 'route', cascade: ['persist', 'refresh'])]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $stages;

    public function __construct(Document $document, int $round, DocumentVersion $version, string $createdBy)
    {
        $this->document = $document;
        $this->round = $round;
        $this->versionNumber = $version->getNumber();
        $this->versionSha256 = $version->getSha256();
        $this->createdBy = $createdBy;
        $this->createdAt = new \DateTimeImmutable();
        $this->stages = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getDocument(): Document { return $this->document; }
    public function getRound(): int { return $this->round; }
    public function getStatus(): string { return $this->status; }
    public function isActive(): bool { return $this->status === self::ACTIVE; }
    public function getVersionNumber(): int { return $this->versionNumber; }
    public function getVersionSha256(): string { return $this->versionSha256; }
    public function getCreatedBy(): string { return $this->createdBy; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getCompletedAt(): ?\DateTimeImmutable { return $this->completedAt; }

    /** @return list<ApprovalStage> */
    public function getStages(): array
    {
        $stages = array_values($this->stages->toArray());
        usort($stages, static fn (ApprovalStage $a, ApprovalStage $b): int => $a->getPosition() <=> $b->getPosition());

        return $stages;
    }

    public function addStage(string $rule): ApprovalStage
    {
        if (count($this->stages) >= self::MAX_STAGES) {
            throw new \InvalidArgumentException(sprintf('A route has at most %d stages.', self::MAX_STAGES));
        }
        $stage = new ApprovalStage($this, count($this->stages) + 1, $rule);
        $this->stages->add($stage);

        return $stage;
    }

    /** Activates the first stage that was not carried over from an earlier round. */
    public function start(\DateTimeImmutable $now): ApprovalStage
    {
        foreach ($this->getStages() as $stage) {
            if ($stage->getStepCount() === 0) {
                throw new \InvalidArgumentException('Every stage needs at least one participant.');
            }
        }
        $next = $this->nextWaitingStage();
        if ($next === null) {
            throw new \InvalidArgumentException('The route has no stage left to run.');
        }
        $next->activate($now);

        return $next;
    }

    public function currentStage(): ?ApprovalStage
    {
        foreach ($this->getStages() as $stage) {
            if ($stage->getStatus() === ApprovalStage::ACTIVE) {
                return $stage;
            }
        }

        return null;
    }

    /**
     * Records a decision on a step of the active stage and moves the route on.
     *
     * @return string the outcome: 'pending' (stage still open), 'next_stage', 'approved', 'rejected' or 'returned'
     */
    public function decide(ApprovalStep $step, string $decision, string $actor, ?string $onBehalfOf, ?string $comment, \DateTimeImmutable $now): string
    {
        $this->assertOpenStep($step);
        if ($step->isConsultation()) {
            throw new \DomainException('A consultation is answered with a review, not a decision.');
        }
        $step->decide($decision, $actor, $onBehalfOf, $comment, $now);
        $stage = $step->getStage();
        foreach ($stage->getSteps() as $consultation) {
            if ($consultation->getParent() === $step && $consultation->isOpen()) {
                $consultation->cancel(ApprovalStep::CANCELLED);
            }
        }
        if ($decision === ApprovalStep::REJECTED || $decision === ApprovalStep::RETURNED) {
            $stage->close($decision === ApprovalStep::REJECTED ? ApprovalStage::REJECTED : ApprovalStage::RETURNED, $now);
            $this->finish($decision === ApprovalStep::REJECTED ? self::REJECTED : self::RETURNED, $now);

            return $decision;
        }
        if (!$stage->isSatisfied()) {
            return 'pending';
        }
        $stage->close(ApprovalStage::COMPLETED, $now);
        $next = $this->nextWaitingStage();
        if ($next !== null) {
            $next->activate($now);

            return 'next_stage';
        }
        $this->finish(self::APPROVED, $now);

        return self::APPROVED;
    }

    /** Asks a subordinate of the step's assignee for an advisory review; the step itself stays open. */
    public function consult(ApprovalStep $step, User $consultee, ?string $position, ?string $unit, ?\DateTimeImmutable $dueOn, string $requestedBy): ApprovalStep
    {
        $this->assertOpenStep($step);
        if ($step->isConsultation()) {
            throw new \DomainException('A consultation cannot be forwarded again.');
        }
        foreach ($step->getStage()->getSteps() as $other) {
            if ($other->getParent() === $step && $other->getAssignee()->getId() === $consultee->getId() && $other->isOpen()) {
                throw new \DomainException('That person has already been asked for a review.');
            }
        }

        return $step->getStage()->addStep($consultee, ApprovalStep::CONSULT, $position, $unit, $dueOn, $requestedBy, $step, true);
    }

    public function review(ApprovalStep $consultation, string $actor, ?string $onBehalfOf, string $comment, \DateTimeImmutable $now): void
    {
        $this->assertOpenStep($consultation);
        if (!$consultation->isConsultation()) {
            throw new \DomainException('Only a consultation is answered with a review.');
        }
        $consultation->decide(ApprovalStep::REVIEWED, $actor, $onBehalfOf, $comment, $now);
    }

    /** The author adds a reviewer to the stage that is running now. */
    public function addReviewer(User $reviewer, ?string $position, ?string $unit, ?\DateTimeImmutable $dueOn, string $addedBy): ApprovalStep
    {
        $stage = $this->currentStage();
        if (!$this->isActive() || $stage === null) {
            throw new \DomainException('Reviewers can only be added while the approval is running.');
        }

        return $stage->addStep($reviewer, ApprovalStep::REVIEW, $position, $unit, $dueOn, $addedBy, null, true);
    }

    public function withdraw(\DateTimeImmutable $now): void
    {
        if (!$this->isActive()) {
            throw new \DomainException('The approval is already finished.');
        }
        $this->currentStage()?->close(ApprovalStage::CANCELLED, $now);
        $this->finish(self::WITHDRAWN, $now);
    }

    /** @return list<ApprovalStep> every step, consultations included, in stage order */
    public function allSteps(): array
    {
        $steps = [];
        foreach ($this->getStages() as $stage) {
            array_push($steps, ...$stage->getSteps());
        }

        return $steps;
    }

    private function nextWaitingStage(): ?ApprovalStage
    {
        foreach ($this->getStages() as $stage) {
            if ($stage->getStatus() === ApprovalStage::WAITING) {
                return $stage;
            }
        }

        return null;
    }

    private function assertOpenStep(ApprovalStep $step): void
    {
        if ($step->getStage()->getRoute() !== $this) {
            throw new \LogicException('The step belongs to another route.');
        }
        if (!$this->isActive() || $step->getStage()->getStatus() !== ApprovalStage::ACTIVE || !$step->isOpen()) {
            throw new \DomainException('This approval step is no longer open.');
        }
    }

    private function finish(string $status, \DateTimeImmutable $now): void
    {
        $this->status = $status;
        $this->completedAt = $now;
        foreach ($this->getStages() as $stage) {
            $stage->cancelOpenSteps();
            if ($stage->getStatus() === ApprovalStage::WAITING) {
                $stage->close(ApprovalStage::CANCELLED, $now);
            }
        }
    }
}
