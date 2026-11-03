<?php

namespace App\Entity;

use App\Repository\OrganizationSettingsRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OrganizationSettingsRepository::class)]
#[ORM\Table(name: 'organization_settings')]
class OrganizationSettings
{
    /** @var list<string> */
    public const SUPPORTED_COUNTRIES = ['LV', 'LT', 'EE', 'DE', 'GB'];

    /** @var list<string> */
    public const SUPPORTED_CURRENCIES = ['EUR', 'GBP', 'USD'];

    #[ORM\Id]
    #[ORM\Column(type: Types::SMALLINT)]
    private int $id = 1;

    #[ORM\Column(length: 2, nullable: true)]
    private ?string $countryCode = null;

    #[ORM\Column(length: 3, nullable: true)]
    private ?string $defaultCurrency = null;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 10])]
    private int $retentionYears = 10;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $updatedBy = null;

    #[ORM\ManyToOne(targetEntity: LedgerAccount::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?LedgerAccount $accountsReceivableAccount = null;

    #[ORM\ManyToOne(targetEntity: LedgerAccount::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?LedgerAccount $revenueAccount = null;

    #[ORM\ManyToOne(targetEntity: LedgerAccount::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?LedgerAccount $cashAccount = null;

    #[ORM\ManyToOne(targetEntity: LedgerAccount::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?LedgerAccount $accountsPayableAccount = null;

    #[ORM\ManyToOne(targetEntity: LedgerAccount::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?LedgerAccount $projectExpenseAccount = null;

    #[ORM\ManyToOne(targetEntity: LedgerAccount::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?LedgerAccount $expensePayableAccount = null;

    #[ORM\ManyToOne(targetEntity: LedgerAccount::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?LedgerAccount $inventoryAccount = null;

    #[ORM\ManyToOne(targetEntity: LedgerAccount::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?LedgerAccount $costOfSalesAccount = null;

    #[ORM\ManyToOne(targetEntity: LedgerAccount::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?LedgerAccount $goodsReceivedNotInvoicedAccount = null;

    #[ORM\ManyToOne(targetEntity: LedgerAccount::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?LedgerAccount $inventoryAdjustmentAccount = null;

    #[ORM\ManyToOne(targetEntity: LedgerAccount::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?LedgerAccount $purchasePriceVarianceAccount = null;

    /** Purchase orders at or above this value (in the default currency) need approval; null disables approvals. */
    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $purchaseApprovalThresholdMinor = null;

    #[ORM\ManyToOne(targetEntity: LedgerAccount::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?LedgerAccount $taxPayableAccount = null;

    /** The issuing organisation as it must appear on invoices. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $legalName = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $registrationNumber = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $vatNumber = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $legalAddress = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $contactEmail = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $paymentInstructions = null;

    public function getId(): int
    {
        return $this->id;
    }

    public function getCountryCode(): ?string
    {
        return $this->countryCode;
    }

    public function getDefaultCurrency(): ?string
    {
        return $this->defaultCurrency;
    }

    public function getRetentionYears(): int
    {
        return $this->retentionYears;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getUpdatedBy(): ?string
    {
        return $this->updatedBy;
    }

    /** @return array<string, LedgerAccount|null> */
    public function getAccountMappings(): array
    {
        return [
            'accounts_receivable' => $this->accountsReceivableAccount,
            'revenue' => $this->revenueAccount,
            'cash' => $this->cashAccount,
            'accounts_payable' => $this->accountsPayableAccount,
            'project_expense' => $this->projectExpenseAccount,
            'expense_payable' => $this->expensePayableAccount,
            'inventory' => $this->inventoryAccount,
            'cost_of_sales' => $this->costOfSalesAccount,
            'goods_received_not_invoiced' => $this->goodsReceivedNotInvoicedAccount,
            'inventory_adjustment' => $this->inventoryAdjustmentAccount,
            'purchase_price_variance' => $this->purchasePriceVarianceAccount,
            'tax_payable' => $this->taxPayableAccount,
        ];
    }

    /** @param array<string, LedgerAccount|null> $mappings */
    public function setAccountMappings(array $mappings): static
    {
        $this->accountsReceivableAccount = $mappings['accounts_receivable'] ?? null;
        $this->revenueAccount = $mappings['revenue'] ?? null;
        $this->cashAccount = $mappings['cash'] ?? null;
        $this->accountsPayableAccount = $mappings['accounts_payable'] ?? null;
        $this->projectExpenseAccount = $mappings['project_expense'] ?? null;
        $this->expensePayableAccount = $mappings['expense_payable'] ?? null;
        $this->inventoryAccount = $mappings['inventory'] ?? null;
        $this->costOfSalesAccount = $mappings['cost_of_sales'] ?? null;
        $this->goodsReceivedNotInvoicedAccount = $mappings['goods_received_not_invoiced'] ?? null;
        $this->inventoryAdjustmentAccount = $mappings['inventory_adjustment'] ?? null;
        $this->purchasePriceVarianceAccount = $mappings['purchase_price_variance'] ?? null;
        $this->taxPayableAccount = $mappings['tax_payable'] ?? null;

        return $this;
    }

    public function setLegalProfile(?string $legalName, ?string $registrationNumber, ?string $vatNumber, ?string $legalAddress, ?string $contactEmail, ?string $paymentInstructions): static
    {
        $this->legalName = $legalName;
        $this->registrationNumber = $registrationNumber;
        $this->vatNumber = $vatNumber;
        $this->legalAddress = $legalAddress;
        $this->contactEmail = $contactEmail;
        $this->paymentInstructions = $paymentInstructions;

        return $this;
    }

    public function getLegalName(): ?string { return $this->legalName; }
    public function getRegistrationNumber(): ?string { return $this->registrationNumber; }
    public function getVatNumber(): ?string { return $this->vatNumber; }
    public function getLegalAddress(): ?string { return $this->legalAddress; }
    public function getContactEmail(): ?string { return $this->contactEmail; }
    public function getPaymentInstructions(): ?string { return $this->paymentInstructions; }

    public function getPurchaseApprovalThresholdMinor(): ?int
    {
        return $this->purchaseApprovalThresholdMinor;
    }

    public function setPurchaseApprovalThresholdMinor(?int $thresholdMinor): static
    {
        if ($thresholdMinor !== null && $thresholdMinor < 0) {
            throw new \InvalidArgumentException('The approval threshold cannot be negative.');
        }
        $this->purchaseApprovalThresholdMinor = $thresholdMinor;

        return $this;
    }

    public function update(
        ?string $countryCode,
        ?string $defaultCurrency,
        int $retentionYears,
        string $actor,
        \DateTimeImmutable $updatedAt,
    ): static {
        $this->countryCode = $countryCode;
        $this->defaultCurrency = $defaultCurrency;
        $this->retentionYears = $retentionYears;
        $this->updatedBy = $actor;
        $this->updatedAt = $updatedAt;

        return $this;
    }
}
