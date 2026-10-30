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
