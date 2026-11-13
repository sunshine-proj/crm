<?php

namespace App\Entity;

use App\Repository\ApprovalStepRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One participant's part in a stage. The assignee's e-mail, position and unit are snapshotted when the step is
 * created, so the record keeps showing who held which post at the time even after the structure changes. The
 * decision records who actually acted and, for a deputy, on whose behalf.
 */
#[ORM\Entity(repositoryClass: ApprovalStepRepository::class)]
#[ORM\Table(name: 'approval_step')]
#[ORM\Index(columns: ['assignee_id', 'status'], name: 'idx_approval_step_assignee')]
#[ORM\Index(columns: ['status', 'due_on'], name: 'idx_approval_step_due')]
class ApprovalStep
{
    public const REVIEW = 'review';
    public const APPROVE = 'approve';
    public const SIGN = 'sign';
    /** Advisory opinion requested by an approver from a subordinate; never blocks the stage. */
    public const CONSULT = 'consult';
    public const KINDS = [self::REVIEW, self::APPROVE, self::SIGN];
    /** Kinds that only a superior of the author may hold. */
    public const SUPERIOR_KINDS = [self::APPROVE, self::SIGN];

    public const WAITING = 'waiting';
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    public const RETURNED = 'returned';
    public const REVIEWED = 'reviewed';
    public const SKIPPED = 'skipped';
    public const CANCELLED = 'cancelled';
    public const CARRIED = 'carried';
    public const DECISIONS = [self::APPROVED, self::REJECTED, self::RETURNED];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ApprovalStage::class, inversedBy: 'steps')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ApprovalStage $stage;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?ApprovalStep $parent;

    #[ORM\Column(length: 8)]
    private string $kind;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $assignee;

    #[ORM\Column(length: 180)]
    private string $assigneeEmail;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $assigneePosition;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $assigneeUnit;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dueOn;

    #[ORM\Column(length: 16)]
    private string $status = self::WAITING;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $openedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $decidedBy = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $onBehalfOf = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment = null;

    #[ORM\Column(length: 180)]
    private string $addedBy;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(ApprovalStage $stage, User $assignee, string $kind, ?string $position, ?string $unit, ?\DateTimeImmutable $dueOn, string $addedBy, ?ApprovalStep $parent)
    {
        if (!in_array($kind, [...self::KINDS, self::CONSULT], true)) {
            throw new \InvalidArgumentException('A step is a review, an approval or a signature.');
        }
        if (($kind === self::CONSULT) !== ($parent !== null)) {
            throw new \LogicException('Consultations, and only they, belong to a parent step.');
        }
        $this->stage = $stage;
        $this->assignee = $assignee;
        $this->assigneeEmail = $assignee->getUserIdentifier();
        $this->assigneePosition = $position;
        $this->assigneeUnit = $unit;
        $this->kind = $kind;
        $this->dueOn = $dueOn?->setTime(0, 0);
        $this->addedBy = $addedBy;
        $this->parent = $parent;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getStage(): ApprovalStage { return $this->stage; }
    public function getParent(): ?ApprovalStep { return $this->parent; }
    public function getKind(): string { return $this->kind; }
    public function isConsultation(): bool { return $this->kind === self::CONSULT; }
    public function getAssignee(): User { return $this->assignee; }
    public function getAssigneeEmail(): string { return $this->assigneeEmail; }
    public function getAssigneePosition(): ?string { return $this->assigneePosition; }
    public function getAssigneeUnit(): ?string { return $this->assigneeUnit; }
    public function getDueOn(): ?\DateTimeImmutable { return $this->dueOn; }
    public function getStatus(): string { return $this->status; }
    public function isOpen(): bool { return $this->status === self::PENDING; }
    public function getOpenedAt(): ?\DateTimeImmutable { return $this->openedAt; }
    public function getDecidedAt(): ?\DateTimeImmutable { return $this->decidedAt; }
    public function getDecidedBy(): ?string { return $this->decidedBy; }
    public function getOnBehalfOf(): ?string { return $this->onBehalfOf; }
    public function getComment(): ?string { return $this->comment; }
    public function getAddedBy(): string { return $this->addedBy; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function isOverdue(\DateTimeImmutable $today): bool
    {
        return $this->isOpen() && $this->dueOn !== null && $this->dueOn->format('Y-m-d') < $today->format('Y-m-d');
    }

    public function open(): void
    {
        if ($this->status === self::WAITING) {
            $this->status = self::PENDING;
            $this->openedAt = new \DateTimeImmutable();
        }
    }

    public function decide(string $decision, string $actor, ?string $onBehalfOf, ?string $comment, \DateTimeImmutable $now): void
    {
        if (!in_array($decision, [...self::DECISIONS, self::REVIEWED], true)) {
            throw new \InvalidArgumentException('Unknown decision.');
        }
        $comment = $comment !== null ? trim($comment) : null;
        if (in_array($decision, [self::REJECTED, self::RETURNED, self::REVIEWED], true) && ($comment === null || $comment === '')) {
            throw new \InvalidArgumentException($decision === self::REVIEWED ? 'Write your review.' : 'Give a reason so the author knows what to change.');
        }
        if ($comment !== null && mb_strlen($comment) > 4000) {
            throw new \InvalidArgumentException('A comment is at most 4000 characters.');
        }
        $this->status = $decision;
        $this->decidedAt = $now;
        $this->decidedBy = $actor;
        $this->onBehalfOf = $onBehalfOf;
        $this->comment = $comment === '' ? null : $comment;
    }

    public function cancel(string $status): void
    {
        $this->status = $status;
    }

    public function carry(ApprovalStep $previous): void
    {
        $this->status = self::CARRIED;
        $this->decidedAt = $previous->getDecidedAt();
        $this->decidedBy = $previous->getDecidedBy();
        $this->onBehalfOf = $previous->getOnBehalfOf();
        $this->comment = $previous->getComment();
    }
}
