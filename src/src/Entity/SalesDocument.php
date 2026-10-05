<?php

namespace App\Entity;

use App\Repository\SalesDocumentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SalesDocumentRepository::class)]
#[ORM\Table(name: 'sales_document')]
#[ORM\Index(columns: ['client_id', 'type'], name: 'idx_sales_document_client_type')]
#[ORM\Index(columns: ['source_quote_id'], name: 'idx_sales_document_source_quote')]
#[ORM\UniqueConstraint(name: 'UNIQ_SALES_DOCUMENT_NUMBER', columns: ['document_number'])]
class SalesDocument
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 16)]
    private string $type;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $documentNumber = null;

    #[ORM\ManyToOne(targetEntity: Client::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Client $client;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?self $sourceQuote = null;

    #[ORM\Column(length: 3)]
    private string $currency;

    /** @var list<array{product_id: int, sku: string, description: string, quantity: int, unit_price_minor: int, line_total_minor: int}> */
    #[ORM\Column(type: Types::JSON)]
    private array $items = [];

    #[ORM\Column(type: Types::BIGINT)]
    private int $totalMinor = 0;

    #[ORM\Column(length: 24)]
    private string $status;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $validUntil = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /** @param list<array{product_id: int, sku: string, description: string, quantity: int, unit_price_minor: int, line_total_minor: int}> $items */
    public function __construct(string $type, Client $client, string $currency, array $items, int $totalMinor, ?\DateTimeImmutable $validUntil = null, ?self $sourceQuote = null)
    {
        $this->type = $type;
        $this->client = $client;
        $this->currency = $currency;
        $this->items = $items;
        $this->totalMinor = $totalMinor;
        $this->validUntil = $validUntil;
        $this->sourceQuote = $sourceQuote;
        $this->status = $type === 'quote' ? 'draft' : 'open';
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int { return $this->id; }
    public function getType(): string { return $this->type; }
    public function getDocumentNumber(): ?string { return $this->documentNumber; }
    public function getClient(): Client { return $this->client; }
    public function getSourceQuote(): ?self { return $this->sourceQuote; }
    public function getCurrency(): string { return $this->currency; }
    /** @return list<array{product_id: int, sku: string, description: string, quantity: int, unit_price_minor: int, line_total_minor: int}> */
    public function getItems(): array { return $this->items; }
    public function getTotalMinor(): int { return $this->totalMinor; }
    public function getStatus(): string { return $this->status; }
    public function getValidUntil(): ?\DateTimeImmutable { return $this->validUntil; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function setDocumentNumber(string $documentNumber): static
    {
        $this->documentNumber = $documentNumber;
        return $this;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }
}