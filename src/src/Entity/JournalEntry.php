<?php

namespace App\Entity;

use App\Repository\JournalEntryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: JournalEntryRepository::class)]
#[ORM\Table(name: 'journal_entry')]
#[ORM\Index(columns: ['period_id', 'entry_date'], name: 'idx_journal_entry_period_date')]
#[ORM\Index(columns: ['source_type', 'source_id'], name: 'idx_journal_entry_source')]
#[ORM\UniqueConstraint(name: 'UNIQ_JOURNAL_ENTRY_NUMBER', columns: ['entry_number'])]
class JournalEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $entryNumber = null;

    #[ORM\ManyToOne(targetEntity: FiscalPeriod::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private FiscalPeriod $period;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $entryDate;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(length: 255)]
    private string $description;

    #[ORM\Column(length: 24)]
    private string $sourceType;

    #[ORM\Column(nullable: true)]
    private ?int $sourceId = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, JournalEntryLine> */
    #[ORM\OneToMany(mappedBy: 'entry', targetEntity: JournalEntryLine::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $lines;

    public function __construct(FiscalPeriod $period, \DateTimeImmutable $entryDate, string $currency, string $description, string $sourceType = 'manual', ?int $sourceId = null)
    {
        $this->period = $period;
        $this->entryDate = $entryDate;
        $this->currency = $currency;
        $this->description = $description;
        $this->sourceType = $sourceType;
        $this->sourceId = $sourceId;
        $this->createdAt = new \DateTimeImmutable();
        $this->lines = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getEntryNumber(): ?string { return $this->entryNumber; }
    public function getPeriod(): FiscalPeriod { return $this->period; }
    public function getEntryDate(): \DateTimeImmutable { return $this->entryDate; }
    public function getCurrency(): string { return $this->currency; }
    public function getDescription(): string { return $this->description; }
    public function getSourceType(): string { return $this->sourceType; }
    public function getSourceId(): ?int { return $this->sourceId; }
    /** @return Collection<int, JournalEntryLine> */
    public function getLines(): Collection { return $this->lines; }
    public function setEntryNumber(string $number): static { $this->entryNumber = $number; return $this; }

    public function addLine(JournalEntryLine $line): static
    {
        $this->lines->add($line);
        return $this;
    }
}