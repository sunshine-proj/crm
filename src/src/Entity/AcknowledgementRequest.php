<?php

namespace App\Entity;

use App\Repository\AcknowledgementRequestRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A familiarization campaign: management asks people to read one exact version of a registered document and
 * confirm it. The version number and SHA-256 are pinned when it is sent. The targets (units, positions, people)
 * are kept as chosen, so people who join a targeted unit or position later can be added while the request is open.
 * Who was asked is the snapshot in AcknowledgementRecipient, never recomputed from today's structure.
 */
#[ORM\Entity(repositoryClass: AcknowledgementRequestRepository::class)]
#[ORM\Table(name: 'ack_request')]
#[ORM\Index(columns: ['document_id', 'created_at'], name: 'idx_ack_request_document')]
#[ORM\Index(columns: ['status', 'due_on'], name: 'idx_ack_request_due')]
class AcknowledgementRequest
{
    public const OPEN = 'open';
    public const CLOSED = 'closed';

    public const TARGET_UNIT = 'unit';
    public const TARGET_POSITION = 'position';
    public const TARGET_USER = 'user';
    public const TARGET_KINDS = [self::TARGET_UNIT, self::TARGET_POSITION, self::TARGET_USER];
    public const MAX_TARGETS = 200;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Document::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Document $document;

    #[ORM\Column]
    private int $versionNumber;

    #[ORM\Column(length: 64)]
    private string $versionSha256;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $sender;

    #[ORM\Column(length: 180)]
    private string $senderEmail;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $message;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dueOn;

    #[ORM\Column]
    private bool $requireOpen;

    #[ORM\Column]
    private bool $autoAddJoiners;

    /** @var list<array{kind: string, id: int, include_subunits: bool, label: string}> */
    #[ORM\Column(type: Types::JSON)]
    private array $targets = [];

    #[ORM\Column(length: 8)]
    private string $status = self::OPEN;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?AcknowledgementRequest $previous;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $closedBy = null;

    /** @param list<array{kind: string, id: int, include_subunits: bool, label: string}> $targets */
    public function __construct(Document $document, DocumentVersion $version, User $sender, array $targets, ?string $message, ?\DateTimeImmutable $dueOn, bool $requireOpen, bool $autoAddJoiners, ?AcknowledgementRequest $previous = null)
    {
        if ($version->getDocument() !== $document) {
            throw new \LogicException('The version belongs to another document.');
        }
        $this->document = $document;
        $this->versionNumber = $version->getNumber();
        $this->versionSha256 = $version->getSha256();
        $this->sender = $sender;
        $this->senderEmail = $sender->getUserIdentifier();
        $message = $message !== null ? trim($message) : null;
        $this->message = $message === '' ? null : $message;
        $this->dueOn = $dueOn?->setTime(0, 0);
        $this->requireOpen = $requireOpen;
        $this->autoAddJoiners = $autoAddJoiners;
        $this->previous = $previous;
        $this->createdAt = new \DateTimeImmutable();
        $this->addTargets($targets);
    }

    public function getId(): ?int { return $this->id; }
    public function getDocument(): Document { return $this->document; }
    public function getVersionNumber(): int { return $this->versionNumber; }
    public function getVersionSha256(): string { return $this->versionSha256; }
    public function getSender(): User { return $this->sender; }
    public function getSenderEmail(): string { return $this->senderEmail; }
    public function getMessage(): ?string { return $this->message; }
    public function getDueOn(): ?\DateTimeImmutable { return $this->dueOn; }
    public function requiresOpening(): bool { return $this->requireOpen; }
    public function addsJoiners(): bool { return $this->autoAddJoiners; }
    /** @return list<array{kind: string, id: int, include_subunits: bool, label: string}> */
    public function getTargets(): array { return $this->targets; }
    public function getStatus(): string { return $this->status; }
    public function isOpen(): bool { return $this->status === self::OPEN; }
    public function getPrevious(): ?AcknowledgementRequest { return $this->previous; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getClosedAt(): ?\DateTimeImmutable { return $this->closedAt; }
    public function getClosedBy(): ?string { return $this->closedBy; }

    public function isPastDue(\DateTimeImmutable $today): bool
    {
        return $this->dueOn !== null && $this->dueOn->format('Y-m-d') < $today->format('Y-m-d');
    }

    /** @param list<array{kind: string, id: int, include_subunits: bool, label: string}> $targets */
    public function addTargets(array $targets): void
    {
        $this->assertOpen();
        $merged = $this->targets;
        foreach ($targets as $target) {
            if (!in_array($target['kind'], self::TARGET_KINDS, true)) {
                throw new \InvalidArgumentException('Recipients are chosen by unit, position or person.');
            }
            foreach ($merged as $index => $known) {
                if ($known['kind'] === $target['kind'] && $known['id'] === $target['id']) {
                    // Already targeted; widening a unit to its sub-units is kept.
                    $merged[$index]['include_subunits'] = $known['include_subunits'] || $target['include_subunits'];
                    continue 2;
                }
            }
            $merged[] = ['kind' => $target['kind'], 'id' => $target['id'], 'include_subunits' => $target['include_subunits'], 'label' => mb_substr($target['label'], 0, 255)];
        }
        if (count($merged) > self::MAX_TARGETS) {
            throw new \InvalidArgumentException(sprintf('A request can have at most %d units, positions and people.', self::MAX_TARGETS));
        }
        $this->targets = $merged;
    }

    /** Moves the deadline; it can be extended or set, never moved into the past. */
    public function extendTo(\DateTimeImmutable $dueOn, \DateTimeImmutable $today): void
    {
        $this->assertOpen();
        if ($dueOn->format('Y-m-d') < $today->format('Y-m-d')) {
            throw new \InvalidArgumentException('The new deadline cannot be in the past.');
        }
        if ($this->dueOn !== null && $dueOn->format('Y-m-d') <= $this->dueOn->format('Y-m-d')) {
            throw new \InvalidArgumentException(sprintf('The deadline can only be extended beyond %s.', $this->dueOn->format('Y-m-d')));
        }
        $this->dueOn = $dueOn->setTime(0, 0);
    }

    public function close(string $by, \DateTimeImmutable $now): void
    {
        $this->assertOpen();
        $this->status = self::CLOSED;
        $this->closedAt = $now;
        $this->closedBy = $by;
    }

    public function assertOpen(): void
    {
        if ($this->status !== self::OPEN) {
            throw new \DomainException('This acknowledgement request is closed.');
        }
    }
}
