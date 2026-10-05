<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'journal_entry_line')]
#[ORM\Index(columns: ['entry_id'], name: 'idx_journal_line_entry')]
#[ORM\Index(columns: ['account_id'], name: 'idx_journal_line_account')]
class JournalEntryLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: JournalEntry::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private JournalEntry $entry;

    #[ORM\ManyToOne(targetEntity: LedgerAccount::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private LedgerAccount $account;

    #[ORM\Column(type: 'bigint')]
    private int $debitMinor;

    #[ORM\Column(type: 'bigint')]
    private int $creditMinor;

    #[ORM\Column(length: 255)]
    private string $memo;

    public function __construct(JournalEntry $entry, LedgerAccount $account, int $debitMinor, int $creditMinor, string $memo)
    {
        $this->entry = $entry;
        $this->account = $account;
        $this->debitMinor = $debitMinor;
        $this->creditMinor = $creditMinor;
        $this->memo = $memo;
    }

    public function getId(): ?int { return $this->id; }
    public function getEntry(): JournalEntry { return $this->entry; }
    public function getAccount(): LedgerAccount { return $this->account; }
    public function getDebitMinor(): int { return $this->debitMinor; }
    public function getCreditMinor(): int { return $this->creditMinor; }
    public function getMemo(): string { return $this->memo; }
}