<?php

namespace App\Entity;

use App\Repository\OrganizationSettingsChangeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OrganizationSettingsChangeRepository::class)]
#[ORM\Table(name: 'organization_settings_change')]
class OrganizationSettingsChange
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 2, nullable: true)]
    private ?string $previousCountryCode;

    #[ORM\Column(length: 3, nullable: true)]
    private ?string $previousCurrency;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $previousRetentionYears;

    #[ORM\Column(length: 2, nullable: true)]
    private ?string $newCountryCode;

    #[ORM\Column(length: 3, nullable: true)]
    private ?string $newCurrency;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $newRetentionYears;

    #[ORM\Column(length: 180)]
    private string $actor;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $changedAt;

    public function __construct(
        ?string $previousCountryCode,
        ?string $previousCurrency,
        int $previousRetentionYears,
        ?string $newCountryCode,
        ?string $newCurrency,
        int $newRetentionYears,
        string $actor,
        \DateTimeImmutable $changedAt,
    ) {
        $this->previousCountryCode = $previousCountryCode;
        $this->previousCurrency = $previousCurrency;
        $this->previousRetentionYears = $previousRetentionYears;
        $this->newCountryCode = $newCountryCode;
        $this->newCurrency = $newCurrency;
        $this->newRetentionYears = $newRetentionYears;
        $this->actor = $actor;
        $this->changedAt = $changedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPreviousCountryCode(): ?string
    {
        return $this->previousCountryCode;
    }

    public function getPreviousCurrency(): ?string
    {
        return $this->previousCurrency;
    }

    public function getPreviousRetentionYears(): int
    {
        return $this->previousRetentionYears;
    }

    public function getNewCountryCode(): ?string
    {
        return $this->newCountryCode;
    }

    public function getNewCurrency(): ?string
    {
        return $this->newCurrency;
    }

    public function getNewRetentionYears(): int
    {
        return $this->newRetentionYears;
    }

    public function getActor(): string
    {
        return $this->actor;
    }

    public function getChangedAt(): \DateTimeImmutable
    {
        return $this->changedAt;
    }
}
