<?php

namespace App\Entity;

use App\Repository\AcknowledgementRecipientRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One person asked to acknowledge a request. The e-mail, position and unit are snapshotted when the person is
 * added, so later org changes do not rewrite who was asked or where they worked.
 *
 * States: pending → viewed (first opening of the requested version's file) → acknowledged (explicit confirmation
 * with time and the version's SHA-256). A manager can mark a person excused (leave, absence) with a reason, and undo
 * it. "Overdue" is derived: still pending or viewed after the request's due date.
 */
#[ORM\Entity(repositoryClass: AcknowledgementRecipientRepository::class)]
#[ORM\Table(name: 'ack_recipient')]
#[ORM\UniqueConstraint(name: 'uniq_ack_recipient', columns: ['request_id', 'user_id'])]
#[ORM\Index(columns: ['user_id', 'status'], name: 'idx_ack_recipient_user')]
class AcknowledgementRecipient
{
    public const PENDING = 'pending';
    public const VIEWED = 'viewed';
    public const ACKNOWLEDGED = 'acknowledged';
    public const EXCUSED = 'excused';
    public const STATUSES = [self::PENDING, self::VIEWED, self::ACKNOWLEDGED, self::EXCUSED];
    /** States in which the person still owes a confirmation. */
    public const OPEN_STATES = [self::PENDING, self::VIEWED];

    /** How the person got on the list. */
    public const SENT = 'sent';
    public const ADDED = 'added';
    public const JOINED = 'joined';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: AcknowledgementRequest::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private AcknowledgementRequest $request;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $user;

    #[ORM\Column(length: 180)]
    private string $userEmail;

    #[ORM\ManyToOne(targetEntity: OrgUnit::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?OrgUnit $unit;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $unitName;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $positionTitle;

    #[ORM\Column(length: 8)]
    private string $source;

    #[ORM\Column(length: 180)]
    private string $addedBy;

    #[ORM\Column(length: 16)]
    private string $status = self::PENDING;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $viewedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $acknowledgedAt = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $acknowledgedSha256 = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $excusedAt = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $excusedBy = null;

    #[ORM\Column(length: 1000, nullable: true)]
    private ?string $excuseReason = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastRemindedAt = null;

    #[ORM\Column]
    private int $reminderCount = 0;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(AcknowledgementRequest $request, User $user, ?OrgUnit $unit, ?string $positionTitle, string $source, string $addedBy)
    {
        if (!in_array($source, [self::SENT, self::ADDED, self::JOINED], true)) {
            throw new \InvalidArgumentException('Unknown recipient source.');
        }
        $this->request = $request;
        $this->user = $user;
        $this->userEmail = $user->getUserIdentifier();
        $this->unit = $unit;
        $this->unitName = $unit?->getName();
        $this->positionTitle = $positionTitle;
        $this->source = $source;
        $this->addedBy = $addedBy;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getRequest(): AcknowledgementRequest { return $this->request; }
    public function getUser(): User { return $this->user; }
    public function getUserEmail(): string { return $this->userEmail; }
    public function getUnit(): ?OrgUnit { return $this->unit; }
    public function getUnitName(): ?string { return $this->unitName; }
    public function getPositionTitle(): ?string { return $this->positionTitle; }
    public function getSource(): string { return $this->source; }
    public function getAddedBy(): string { return $this->addedBy; }
    public function getStatus(): string { return $this->status; }
    public function isOpen(): bool { return in_array($this->status, self::OPEN_STATES, true); }
    public function getViewedAt(): ?\DateTimeImmutable { return $this->viewedAt; }
    public function getAcknowledgedAt(): ?\DateTimeImmutable { return $this->acknowledgedAt; }
    public function getAcknowledgedSha256(): ?string { return $this->acknowledgedSha256; }
    public function getExcusedAt(): ?\DateTimeImmutable { return $this->excusedAt; }
    public function getExcusedBy(): ?string { return $this->excusedBy; }
    public function getExcuseReason(): ?string { return $this->excuseReason; }
    public function getLastRemindedAt(): ?\DateTimeImmutable { return $this->lastRemindedAt; }
    public function getReminderCount(): int { return $this->reminderCount; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function isOverdue(\DateTimeImmutable $today): bool
    {
        return $this->isOpen() && $this->request->isOpen() && $this->request->isPastDue($today);
    }

    /** Acknowledged after the due date. */
    public function isLate(): bool
    {
        $due = $this->request->getDueOn();

        return $this->acknowledgedAt !== null && $due !== null && $this->acknowledgedAt->format('Y-m-d') > $due->format('Y-m-d');
    }

    /** Records the first opening of the requested version; returns whether anything changed. */
    public function markViewed(\DateTimeImmutable $now): bool
    {
        if ($this->viewedAt !== null) {
            return false;
        }
        $this->viewedAt = $now;
        if ($this->status === self::PENDING) {
            $this->status = self::VIEWED;
        }

        return true;
    }

    /** The person's own "I have read and understood" confirmation of the pinned version. */
    public function acknowledge(\DateTimeImmutable $now): void
    {
        $this->request->assertOpen();
        if ($this->status === self::ACKNOWLEDGED) {
            throw new \DomainException('You have already acknowledged this document.');
        }
        if ($this->request->requiresOpening() && $this->viewedAt === null) {
            throw new \DomainException('Open the document before confirming that you have read it.');
        }
        $this->status = self::ACKNOWLEDGED;
        $this->acknowledgedAt = $now;
        $this->acknowledgedSha256 = $this->request->getVersionSha256();
    }

    public function excuse(string $reason, string $by, \DateTimeImmutable $now): void
    {
        $this->request->assertOpen();
        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('Give a reason, for example leave or long absence.');
        }
        if ($this->status === self::ACKNOWLEDGED) {
            throw new \DomainException('This person has already acknowledged the document.');
        }
        if ($this->status === self::EXCUSED) {
            throw new \DomainException('This person is already excused.');
        }
        $this->status = self::EXCUSED;
        $this->excuseReason = mb_substr($reason, 0, 1000);
        $this->excusedBy = $by;
        $this->excusedAt = $now;
    }

    /** Undoes an excuse; the person owes the confirmation again. */
    public function reinstate(): void
    {
        $this->request->assertOpen();
        if ($this->status !== self::EXCUSED) {
            throw new \DomainException('Only an excused person can be asked again.');
        }
        $this->status = $this->viewedAt !== null ? self::VIEWED : self::PENDING;
        $this->excuseReason = $this->excusedBy = null;
        $this->excusedAt = null;
    }

    public function reminded(\DateTimeImmutable $now): void
    {
        $this->lastRemindedAt = $now;
        ++$this->reminderCount;
    }
}
