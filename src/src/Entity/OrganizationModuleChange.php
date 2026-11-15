<?php

namespace App\Entity;

use App\Repository\OrganizationModuleChangeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Append-only history of module configuration changes: the state before and after, who changed it and when. */
#[ORM\Entity(repositoryClass: OrganizationModuleChangeRepository::class)]
#[ORM\Table(name: 'organization_module_change')]
#[ORM\Index(columns: ['changed_at'], name: 'idx_organization_module_change_at')]
class OrganizationModuleChange
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 40)]
    private string $moduleKey;

    /** @var array{enabled: bool, allowed_roles: list<string>, settings: array<string, int|bool>} */
    #[ORM\Column(type: Types::JSON)]
    private array $previous;

    /** @var array{enabled: bool, allowed_roles: list<string>, settings: array<string, int|bool>} */
    #[ORM\Column(type: Types::JSON)]
    private array $new;

    #[ORM\Column(length: 180)]
    private string $actor;

    #[ORM\Column]
    private \DateTimeImmutable $changedAt;

    /**
     * @param array{enabled: bool, allowed_roles: list<string>, settings: array<string, int|bool>} $previous
     * @param array{enabled: bool, allowed_roles: list<string>, settings: array<string, int|bool>} $new
     */
    public function __construct(string $moduleKey, array $previous, array $new, string $actor)
    {
        $this->moduleKey = $moduleKey;
        $this->previous = $previous;
        $this->new = $new;
        $this->actor = $actor;
        $this->changedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getModuleKey(): string { return $this->moduleKey; }
    /** @return array{enabled: bool, allowed_roles: list<string>, settings: array<string, int|bool>} */
    public function getPrevious(): array { return $this->previous; }
    /** @return array{enabled: bool, allowed_roles: list<string>, settings: array<string, int|bool>} */
    public function getNew(): array { return $this->new; }
    public function getActor(): string { return $this->actor; }
    public function getChangedAt(): \DateTimeImmutable { return $this->changedAt; }
}
