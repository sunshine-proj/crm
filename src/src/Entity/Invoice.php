<?php

namespace App\Entity;

use App\Repository\InvoiceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InvoiceRepository::class)]
#[ORM\Table(name: 'invoice')]
#[ORM\UniqueConstraint(name: 'UNIQ_INVOICE_NUMBER', columns: ['invoice_number'])]
#[ORM\UniqueConstraint(name: 'UNIQ_INVOICE_SALES_ORDER', columns: ['sales_order_id'])]
class Invoice
{
	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column]
	private ?int $id = null;

	#[ORM\Column(length: 32, nullable: true)]
	private ?string $invoiceNumber = null;

	#[ORM\ManyToOne(targetEntity: Client::class)]
	#[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
	private ?Client $client = null;

	#[ORM\ManyToOne(targetEntity: Project::class)]
	#[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
	private ?Project $project = null;

	#[ORM\ManyToOne(targetEntity: User::class)]
	#[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
	private ?User $manager = null;

	#[ORM\ManyToOne(targetEntity: SalesDocument::class)]
	#[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
	private ?SalesDocument $salesOrder = null;

	#[ORM\Column(length: 255)]
	private string $clientName;

	#[ORM\Column(length: 255)]
	private string $projectName;

	#[ORM\Column(length: 180, nullable: true)]
	private ?string $managerName = null;

	#[ORM\Column(type: Types::DATE_IMMUTABLE)]
	private \DateTimeImmutable $invoiceDate;

	#[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
	private ?\DateTimeImmutable $dueDate = null;

	#[ORM\Column(length: 3)]
	private string $currency;

	/** @var list<array{task_id: int|null, description: string, amount: string, tax_code?: string, tax_rate_bp?: int}> amount is the net line amount */
	#[ORM\Column(type: Types::JSON)]
	private array $items = [];

	/** Net total (before tax); totalMinor is the gross amount that is receivable. */
	#[ORM\Column(type: Types::BIGINT, options: ['default' => 0])]
	private int $netMinor = 0;

	#[ORM\Column(type: Types::BIGINT, options: ['default' => 0])]
	private int $taxMinor = 0;

	/** @var list<array{code: string|null, rate_bp: int, net_minor: int, tax_minor: int}> */
	#[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
	private array $taxBreakdown = [];

	#[ORM\Column(length: 16, nullable: true)]
	private ?string $taxRounding = null;

	/** @var array<string, string|null>|null seller details frozen when the invoice is issued */
	#[ORM\Column(type: Types::JSON, nullable: true)]
	private ?array $issuerSnapshot = null;

	/** @var array<string, string|null>|null customer details frozen when the invoice is issued */
	#[ORM\Column(type: Types::JSON, nullable: true)]
	private ?array $customerSnapshot = null;

	#[ORM\Column(type: Types::BIGINT)]
	private int $totalMinor = 0;

	#[ORM\Column(length: 20, options: ['default' => 'draft'])]
	private string $status = 'draft';

	#[ORM\Column]
	private \DateTimeImmutable $createdAt;

	#[ORM\Column]
	private \DateTimeImmutable $updatedAt;

	public function getId(): ?int
	{
		return $this->id;
	}

	public function getInvoiceNumber(): ?string
	{
		return $this->invoiceNumber;
	}

	public function setInvoiceNumber(string $invoiceNumber): static
	{
		$this->invoiceNumber = $invoiceNumber;

		return $this;
	}

	public function getClient(): ?Client
	{
		return $this->client;
	}

	public function getProject(): ?Project
	{
		return $this->project;
	}

	public function getManager(): ?User
	{
		return $this->manager;
	}

	public function getSalesOrder(): ?SalesDocument
	{
		return $this->salesOrder;
	}

	public function setSalesOrder(?SalesDocument $salesOrder): static
	{
		$this->salesOrder = $salesOrder;

		return $this;
	}

	public function getClientName(): string
	{
		return $this->clientName;
	}

	public function getProjectName(): string
	{
		return $this->projectName;
	}

	public function getManagerName(): ?string
	{
		return $this->managerName;
	}

	public function getInvoiceDate(): \DateTimeImmutable
	{
		return $this->invoiceDate;
	}

	public function getDueDate(): ?\DateTimeImmutable
	{
		return $this->dueDate;
	}

	public function getCurrency(): string
	{
		return $this->currency;
	}

	/** @return list<array{task_id: int|null, description: string, amount: string}> */
	public function getItems(): array
	{
		return $this->items;
	}

	public function getTotalMinor(): int
	{
		return $this->totalMinor;
	}

	public function getStatus(): string
	{
		return $this->status;
	}

	public function setStatus(string $status): static
	{
		$this->status = $status;
		$this->updatedAt = new \DateTimeImmutable();

		return $this;
	}

	public function getCreatedAt(): \DateTimeImmutable
	{
		return $this->createdAt;
	}

	public function getUpdatedAt(): \DateTimeImmutable
	{
		return $this->updatedAt;
	}

	/** @param list<array{task_id: int|null, description: string, amount: string}> $items */
	public function setDetails(
		?Client $client,
		?Project $project,
		?User $manager,
		string $clientName,
		string $projectName,
		?string $managerName,
		\DateTimeImmutable $invoiceDate,
		?\DateTimeImmutable $dueDate,
		string $currency,
		array $items,
		int $totalMinor,
	): static {
		$this->client = $client;
		$this->project = $project;
		$this->manager = $manager;
		$this->clientName = $clientName;
		$this->projectName = $projectName;
		$this->managerName = $managerName;
		$this->invoiceDate = $invoiceDate;
		$this->dueDate = $dueDate;
		$this->currency = $currency;
		$this->items = $items;
		$this->totalMinor = $totalMinor;
		$this->netMinor = $totalMinor;
		$this->taxMinor = 0;
		$this->taxBreakdown = [];
		$this->taxRounding = null;
		$this->updatedAt = new \DateTimeImmutable();
		$this->createdAt ??= $this->updatedAt;

		return $this;
	}

	/**
	 * @param list<array<string, mixed>> $items lines with their tax code and rate
	 * @param list<array{code: string|null, rate_bp: int, net_minor: int, tax_minor: int}> $breakdown
	 */
	public function applyTaxation(array $items, int $netMinor, int $taxMinor, array $breakdown, ?string $rounding): static
	{
		if ($this->status !== 'draft') {
			throw new \DomainException('Tax can only be recalculated on a draft invoice.');
		}
		$this->items = $items;
		$this->netMinor = $netMinor;
		$this->taxMinor = $taxMinor;
		$this->taxBreakdown = $breakdown;
		$this->taxRounding = $rounding;
		$this->totalMinor = $netMinor + $taxMinor;

		return $this;
	}

	/** @param array<string, string|null> $issuer @param array<string, string|null> $customer */
	public function freezeParties(array $issuer, array $customer): static
	{
		$this->issuerSnapshot = $issuer;
		$this->customerSnapshot = $customer;

		return $this;
	}

	public function getNetMinor(): int { return $this->netMinor; }
	public function getTaxMinor(): int { return $this->taxMinor; }
	/** @return list<array{code: string|null, rate_bp: int, net_minor: int, tax_minor: int}> */
	public function getTaxBreakdown(): array { return $this->taxBreakdown; }
	public function getTaxRounding(): ?string { return $this->taxRounding; }
	/** @return array<string, string|null>|null */
	public function getIssuerSnapshot(): ?array { return $this->issuerSnapshot; }
	/** @return array<string, string|null>|null */
	public function getCustomerSnapshot(): ?array { return $this->customerSnapshot; }
}
