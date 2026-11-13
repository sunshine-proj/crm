<?php

namespace App\Entity;

use App\Repository\OrgUnitRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A node of the organization structure (organization → unit → section …). The tree is acyclic and depth-limited;
 * units are archived rather than deleted once they have been used, so historical documents keep their context.
 */
#[ORM\Entity(repositoryClass: OrgUnitRepository::class)]
#[ORM\Table(name: 'org_unit')]
#[ORM\UniqueConstraint(name: 'uniq_org_unit_code', columns: ['code'])]
#[ORM\Index(columns: ['parent_id'], name: 'idx_org_unit_parent')]
class OrgUnit
{
    public const ORGANIZATION = 'organization';
    public const UNIT = 'unit';
    public const SECTION = 'section';
    public const KINDS = [self::ORGANIZATION, self::UNIT, self::SECTION];
    /** Maximum number of levels, the root being level 1. */
    public const MAX_DEPTH = 8;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?OrgUnit $parent = null;

    #[ORM\Column(length: 32)]
    private string $code;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 16)]
    private string $kind;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $archivedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $code, string $name, string $kind, ?OrgUnit $parent = null)
    {
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
        $this->define($code, $name, $kind, $parent);
    }

    public function getId(): ?int { return $this->id; }
    public function getParent(): ?OrgUnit { return $this->parent; }
    public function getCode(): string { return $this->code; }
    public function getName(): string { return $this->name; }
    public function getKind(): string { return $this->kind; }
    public function getArchivedAt(): ?\DateTimeImmutable { return $this->archivedAt; }
    public function isArchived(): bool { return $this->archivedAt !== null; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /**
     * Sets code, name, kind and parent together. Refuses cycles, archived parents, trees deeper than MAX_DEPTH
     * (counting from the new parent only; the caller checks the height of the moved subtree) and an organization
     * anywhere but at the top.
     */
    public function define(string $code, string $name, string $kind, ?OrgUnit $parent): void
    {
        $code = strtoupper(trim($code));
        $name = trim($name);
        if (!preg_match('/^[A-Z0-9][A-Z0-9._-]{0,31}$/', $code)) {
            throw new \InvalidArgumentException('Unit code must be 1-32 letters, digits, dots, dashes or underscores.');
        }
        if ($name === '' || mb_strlen($name) > 255) {
            throw new \InvalidArgumentException('Unit name is required (at most 255 characters).');
        }
        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('Unit kind must be organization, unit or section.');
        }
        if (($kind === self::ORGANIZATION) !== ($parent === null)) {
            throw new \DomainException('Only a top-level unit can be an organization, and a top-level unit must be one.');
        }
        if ($parent !== null && $parent !== $this->parent) {
            if ($parent === $this || $parent->isDescendantOf($this)) {
                throw new \DomainException('A unit cannot be moved under itself or one of its own sub-units.');
            }
            if ($parent->isArchived()) {
                throw new \DomainException('A unit cannot be placed under an archived unit.');
            }
            if ($parent->depth() + 1 > self::MAX_DEPTH) {
                throw new \DomainException(sprintf('The structure is limited to %d levels.', self::MAX_DEPTH));
            }
        }
        $this->code = $code;
        $this->name = $name;
        $this->kind = $kind;
        $this->parent = $parent;
        $this->touch();
    }

    public function isDescendantOf(OrgUnit $ancestor): bool
    {
        for ($node = $this->parent, $guard = 0; $node !== null && $guard <= self::MAX_DEPTH; $node = $node->parent, ++$guard) {
            if ($node === $ancestor) {
                return true;
            }
        }

        return false;
    }

    /** Level in the tree, the root being 1. */
    public function depth(): int
    {
        $depth = 1;
        for ($node = $this->parent; $node !== null && $depth <= self::MAX_DEPTH; $node = $node->parent) {
            ++$depth;
        }

        return $depth;
    }

    public function archive(): void
    {
        if ($this->archivedAt !== null) {
            throw new \DomainException('The unit is already archived.');
        }
        $this->archivedAt = new \DateTimeImmutable();
        $this->touch();
    }

    public function restore(): void
    {
        if ($this->archivedAt === null) {
            throw new \DomainException('The unit is not archived.');
        }
        if ($this->parent?->isArchived()) {
            throw new \DomainException('Restore the parent unit first.');
        }
        $this->archivedAt = null;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
