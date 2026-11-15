<?php

namespace App\Entity;

use App\Repository\OrganizationModuleRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Administrator choice for one functional module of this installation. A module without a row is enabled for
 * everyone with default settings, so upgrading keeps the existing behavior. Turning a module off never deletes data.
 */
#[ORM\Entity(repositoryClass: OrganizationModuleRepository::class)]
#[ORM\Table(name: 'organization_module')]
class OrganizationModule
{
    #[ORM\Id]
    #[ORM\Column(name: 'module_key', length: 40)]
    private string $key;

    #[ORM\Column(options: ['default' => true])]
    private bool $enabled = true;

    /** @var list<string> roles that see the module; empty means every signed-in user */
    #[ORM\Column(type: Types::JSON)]
    private array $allowedRoles = [];

    /** @var array<string, int|bool> */
    #[ORM\Column(type: Types::JSON)]
    private array $settings = [];

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(length: 180)]
    private string $updatedBy;

    public function __construct(string $key, string $actor)
    {
        $this->key = $key;
        $this->updatedAt = new \DateTimeImmutable();
        $this->updatedBy = $actor;
    }

    public function getKey(): string { return $this->key; }
    public function isEnabled(): bool { return $this->enabled; }
    /** @return list<string> */
    public function getAllowedRoles(): array { return $this->allowedRoles; }
    /** @return array<string, int|bool> */
    public function getSettings(): array { return $this->settings; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function getUpdatedBy(): string { return $this->updatedBy; }

    /**
     * @param list<string> $allowedRoles
     * @param array<string, int|bool> $settings
     */
    public function configure(bool $enabled, array $allowedRoles, array $settings, string $actor): void
    {
        $this->enabled = $enabled;
        $this->allowedRoles = array_values($allowedRoles);
        $this->settings = $settings;
        $this->updatedAt = new \DateTimeImmutable();
        $this->updatedBy = $actor;
    }
}
