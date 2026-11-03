<?php

namespace App\Entity;

use App\Repository\ClientRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ClientRepository::class)]
#[ORM\Table(name: 'client')]
#[ORM\Index(columns: ['account_status'], name: 'idx_client_account_status')]
class Client
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $notes = null;

    public const ACCOUNT_STATUSES = ['prospect', 'active', 'inactive'];

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $legalName = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $registrationNumber = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $vatNumber = null;

    #[ORM\Column(length: 2, nullable: true)]
    private ?string $countryCode = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $billingAddress = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $billingEmail = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $website = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $industry = null;

    #[ORM\Column(length: 16, options: ['default' => 'active'])]
    private string $accountStatus = 'active';

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $updatedAt = null;

    /** @var Collection<int, ClientContact> */
    #[ORM\OneToMany(mappedBy: 'client', targetEntity: ClientContact::class)]
    private Collection $contacts;

    public function __construct()
    {
        $this->contacts = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(string $notes): static
    {
        $this->notes = $notes;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    /** @return Collection<int, ClientContact> */
    public function getContacts(): Collection
    {
        return $this->contacts;
    }

    public function getLegalName(): ?string { return $this->legalName; }
    public function getRegistrationNumber(): ?string { return $this->registrationNumber; }
    public function getVatNumber(): ?string { return $this->vatNumber; }
    public function getCountryCode(): ?string { return $this->countryCode; }
    public function getBillingAddress(): ?string { return $this->billingAddress; }
    public function getBillingEmail(): ?string { return $this->billingEmail; }
    public function getWebsite(): ?string { return $this->website; }
    public function getIndustry(): ?string { return $this->industry; }
    public function getAccountStatus(): string { return $this->accountStatus; }

    public function setAccountProfile(
        ?string $legalName,
        ?string $registrationNumber,
        ?string $vatNumber,
        ?string $countryCode,
        ?string $billingAddress,
        ?string $billingEmail,
        ?string $website,
        ?string $industry,
        string $accountStatus,
    ): static {
        if (!in_array($accountStatus, self::ACCOUNT_STATUSES, true)) {
            throw new \InvalidArgumentException('Unsupported account status.');
        }
        $this->legalName = $legalName;
        $this->registrationNumber = $registrationNumber;
        $this->vatNumber = $vatNumber;
        $this->countryCode = $countryCode;
        $this->billingAddress = $billingAddress;
        $this->billingEmail = $billingEmail;
        $this->website = $website;
        $this->industry = $industry;
        $this->accountStatus = $accountStatus;

        return $this;
    }
}
