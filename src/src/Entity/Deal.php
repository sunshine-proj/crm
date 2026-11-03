<?php

namespace App\Entity;

use App\Repository\DealRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DealRepository::class)]
#[ORM\Table(name: 'deal')]
#[ORM\Index(columns: ['client_id'], name: 'idx_deal_client')]
#[ORM\Index(columns: ['stage', 'expected_close_date'], name: 'idx_deal_stage_close')]
#[ORM\Index(columns: ['owner_id'], name: 'idx_deal_owner')]
class Deal
{
    /** Open stages in pipeline order, with the win probability (percent) used for the weighted forecast. */
    public const OPEN_STAGES = ['lead' => 10, 'qualified' => 25, 'proposal' => 50, 'negotiation' => 75];
    public const WON = 'won';
    public const LOST = 'lost';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Client::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Client $client;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $owner = null;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(length: 16, options: ['default' => 'lead'])]
    private string $stage = 'lead';

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $amountMinor = null;

    #[ORM\Column(length: 3, nullable: true)]
    private ?string $currency = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $expectedCloseDate = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $lostReason = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Client $client, string $title)
    {
        if (trim($title) === '') {
            throw new \InvalidArgumentException('A deal needs a title.');
        }
        $this->client = $client;
        $this->title = trim($title);
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getClient(): Client { return $this->client; }
    public function getOwner(): ?User { return $this->owner; }
    public function getTitle(): string { return $this->title; }
    public function getStage(): string { return $this->stage; }
    public function getAmountMinor(): ?int { return $this->amountMinor; }
    public function getCurrency(): ?string { return $this->currency; }
    public function getExpectedCloseDate(): ?\DateTimeImmutable { return $this->expectedCloseDate; }
    public function getLostReason(): ?string { return $this->lostReason; }
    public function getClosedAt(): ?\DateTimeImmutable { return $this->closedAt; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function isOpen(): bool { return isset(self::OPEN_STAGES[$this->stage]); }

    /** Probability in percent for open deals; 0 once closed. */
    public function probability(): int { return self::OPEN_STAGES[$this->stage] ?? 0; }

    public function describe(string $title, ?int $amountMinor, ?string $currency, ?\DateTimeImmutable $expectedCloseDate, ?User $owner): static
    {
        if (trim($title) === '') {
            throw new \InvalidArgumentException('A deal needs a title.');
        }
        if (($amountMinor === null) !== ($currency === null)) {
            throw new \InvalidArgumentException('Amount and currency must be given together.');
        }
        if ($amountMinor !== null && $amountMinor < 0) {
            throw new \InvalidArgumentException('Deal value cannot be negative.');
        }
        $this->title = trim($title);
        $this->amountMinor = $amountMinor;
        $this->currency = $currency;
        $this->expectedCloseDate = $expectedCloseDate;
        $this->owner = $owner;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    /**
     * Open deals may move freely between open stages or close as won/lost. Closed deals are final; a lost deal needs a reason.
     *
     * @throws \DomainException
     */
    public function moveTo(string $stage, ?string $lostReason = null): static
    {
        if (!$this->isOpen()) {
            throw new \DomainException('A closed deal cannot change stage.');
        }
        if (!isset(self::OPEN_STAGES[$stage]) && $stage !== self::WON && $stage !== self::LOST) {
            throw new \DomainException('Unsupported deal stage.');
        }
        if ($stage === $this->stage) {
            throw new \DomainException('The deal is already in this stage.');
        }
        if ($stage === self::LOST && ($lostReason === null || trim($lostReason) === '')) {
            throw new \DomainException('A reason is required to mark a deal as lost.');
        }
        if ($stage === self::WON && ($this->amountMinor === null || $this->currency === null)) {
            throw new \DomainException('Set the deal value and currency before marking it as won.');
        }
        $this->stage = $stage;
        $this->lostReason = $stage === self::LOST ? trim((string) $lostReason) : null;
        $this->closedAt = isset(self::OPEN_STAGES[$stage]) ? null : new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
