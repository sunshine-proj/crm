<?php

namespace App\Entity;

use App\Repository\JurisdictionValidationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A recorded confirmation by a named qualified adviser that the tax rules or the invoice numbering for one country
 * have been reviewed. The application cannot verify the claim; it stores who made it and gates features on it.
 */
#[ORM\Entity(repositoryClass: JurisdictionValidationRepository::class)]
#[ORM\Table(name: 'jurisdiction_validation')]
#[ORM\Index(columns: ['country_code', 'scope', 'revoked_at'], name: 'idx_jurisdiction_validation_lookup')]
class JurisdictionValidation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 2)]
    private string $countryCode;

    /** tax or numbering */
    #[ORM\Column(length: 12)]
    private string $scope;

    #[ORM\Column(length: 160)]
    private string $adviserName;

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $adviserFirm;

    /** Opinion, engagement or document reference the sign-off relies on. */
    #[ORM\Column(length: 255)]
    private string $reference;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $validatedOn;

    /** Tax scope only: how tax is rounded (per_rate_group or per_line), as confirmed by the adviser. */
    #[ORM\Column(length: 16, nullable: true)]
    private ?string $rounding;

    #[ORM\Column(length: 1000, nullable: true)]
    private ?string $notes;

    #[ORM\Column(length: 180)]
    private string $recordedBy;

    #[ORM\Column]
    private \DateTimeImmutable $recordedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $revokedBy = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $revokeReason = null;

    public function __construct(string $countryCode, string $scope, string $adviserName, ?string $adviserFirm, string $reference, \DateTimeImmutable $validatedOn, ?string $rounding, ?string $notes, string $recordedBy)
    {
        if (!in_array($scope, ['tax', 'numbering'], true)) {
            throw new \InvalidArgumentException('Scope must be tax or numbering.');
        }
        if (trim($adviserName) === '' || trim($reference) === '') {
            throw new \InvalidArgumentException('The adviser and the reference they relied on are required.');
        }
        if ($scope === 'tax' && !in_array($rounding, ['per_rate_group', 'per_line'], true)) {
            throw new \InvalidArgumentException('Tax validation needs the rounding method confirmed by the adviser.');
        }
        $this->countryCode = $countryCode;
        $this->scope = $scope;
        $this->adviserName = trim($adviserName);
        $this->adviserFirm = $adviserFirm !== null && trim($adviserFirm) !== '' ? trim($adviserFirm) : null;
        $this->reference = trim($reference);
        $this->validatedOn = $validatedOn;
        $this->rounding = $scope === 'tax' ? $rounding : null;
        $this->notes = $notes !== null && trim($notes) !== '' ? trim($notes) : null;
        $this->recordedBy = $recordedBy;
        $this->recordedAt = new \DateTimeImmutable();
    }

    public function revoke(string $actor, string $reason): static
    {
        if ($this->revokedAt !== null) {
            throw new \DomainException('This validation has already been revoked.');
        }
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('A reason is required to revoke a validation.');
        }
        $this->revokedAt = new \DateTimeImmutable();
        $this->revokedBy = $actor;
        $this->revokeReason = trim($reason);

        return $this;
    }

    public function getId(): ?int { return $this->id; }
    public function getCountryCode(): string { return $this->countryCode; }
    public function getScope(): string { return $this->scope; }
    public function getAdviserName(): string { return $this->adviserName; }
    public function getAdviserFirm(): ?string { return $this->adviserFirm; }
    public function getReference(): string { return $this->reference; }
    public function getValidatedOn(): \DateTimeImmutable { return $this->validatedOn; }
    public function getRounding(): ?string { return $this->rounding; }
    public function getNotes(): ?string { return $this->notes; }
    public function getRecordedBy(): string { return $this->recordedBy; }
    public function getRecordedAt(): \DateTimeImmutable { return $this->recordedAt; }
    public function getRevokedAt(): ?\DateTimeImmutable { return $this->revokedAt; }
    public function getRevokedBy(): ?string { return $this->revokedBy; }
    public function getRevokeReason(): ?string { return $this->revokeReason; }
    public function isActive(): bool { return $this->revokedAt === null; }
}
