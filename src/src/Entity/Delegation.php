<?php

namespace App\Entity;

use App\Repository\DelegationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A deputy acting for a principal during a date range (leave, business trip). Approval and acknowledgement tasks
 * addressed to the principal are redirected to the deputy, and both names are kept on the decision. A delegation
 * is revoked, never edited or deleted, so the record of who could act for whom stays intact.
 */
#[ORM\Entity(repositoryClass: DelegationRepository::class)]
#[ORM\Table(name: 'delegation')]
#[ORM\Index(columns: ['principal_id', 'starts_on'], name: 'idx_delegation_principal')]
#[ORM\Index(columns: ['deputy_id'], name: 'idx_delegation_deputy')]
class Delegation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $principal;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $deputy;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $startsOn;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $endsOn;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $reason;

    #[ORM\Column(length: 180)]
    private string $createdBy;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $revokedBy = null;

    public function __construct(User $principal, User $deputy, \DateTimeImmutable $startsOn, \DateTimeImmutable $endsOn, ?string $reason, string $createdBy)
    {
        if ($principal === $deputy) {
            throw new \InvalidArgumentException('A user cannot be their own deputy.');
        }
        if ($endsOn->format('Y-m-d') < $startsOn->format('Y-m-d')) {
            throw new \InvalidArgumentException('A delegation cannot end before it starts.');
        }
        $reason = $reason !== null ? trim($reason) : null;
        $this->principal = $principal;
        $this->deputy = $deputy;
        $this->startsOn = $startsOn->setTime(0, 0);
        $this->endsOn = $endsOn->setTime(0, 0);
        $this->reason = $reason === '' ? null : $reason;
        $this->createdBy = $createdBy;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getPrincipal(): User { return $this->principal; }
    public function getDeputy(): User { return $this->deputy; }
    public function getStartsOn(): \DateTimeImmutable { return $this->startsOn; }
    public function getEndsOn(): \DateTimeImmutable { return $this->endsOn; }
    public function getReason(): ?string { return $this->reason; }
    public function getCreatedBy(): string { return $this->createdBy; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getRevokedAt(): ?\DateTimeImmutable { return $this->revokedAt; }
    public function getRevokedBy(): ?string { return $this->revokedBy; }
    public function isRevoked(): bool { return $this->revokedAt !== null; }

    public function isActiveOn(\DateTimeImmutable $date): bool
    {
        $day = $date->format('Y-m-d');

        return $this->revokedAt === null && $this->startsOn->format('Y-m-d') <= $day && $this->endsOn->format('Y-m-d') >= $day;
    }

    public function overlaps(\DateTimeImmutable $startsOn, \DateTimeImmutable $endsOn): bool
    {
        return $this->revokedAt === null
            && $this->startsOn->format('Y-m-d') <= $endsOn->format('Y-m-d')
            && $this->endsOn->format('Y-m-d') >= $startsOn->format('Y-m-d');
    }

    public function revoke(string $actor): void
    {
        if ($this->revokedAt !== null) {
            throw new \DomainException('The delegation is already revoked.');
        }
        $this->revokedAt = new \DateTimeImmutable();
        $this->revokedBy = $actor;
    }
}
