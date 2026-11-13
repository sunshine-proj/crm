<?php

namespace App\Entity;

use App\Repository\DocumentTypeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A kind of document (order, policy, memo …): how its registration number is built, which fields must be filled
 * before registration, whether it needs approval, its default confidentiality and how long it is retained.
 */
#[ORM\Entity(repositoryClass: DocumentTypeRepository::class)]
#[ORM\Table(name: 'document_type')]
#[ORM\UniqueConstraint(name: 'uniq_document_type_code', columns: ['code'])]
class DocumentType
{
    /** Fields a type can make mandatory before registration. */
    public const REQUIRABLE_FIELDS = ['summary', 'owner_unit', 'effective_on', 'expires_on', 'review_on', 'client', 'project'];
    public const DEFAULT_PATTERN = '{CODE}-{YYYY}-{SEQ:4}';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 16)]
    private string $code;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 1000, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 64)]
    private string $numberPattern = self::DEFAULT_PATTERN;

    #[ORM\Column(options: ['default' => true])]
    private bool $requiresApproval = true;

    #[ORM\Column(length: 16, options: ['default' => 'general'])]
    private string $defaultConfidentiality = Document::GENERAL;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $retentionYears = null;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $requiredFields = [];

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $code, string $name)
    {
        $code = strtoupper(trim($code));
        if (!preg_match('/^[A-Z][A-Z0-9]{0,15}$/', $code)) {
            throw new \InvalidArgumentException('Type code must be 1-16 letters or digits, starting with a letter.');
        }
        $this->code = $code;
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
        $this->rename($name, null);
    }

    public function getId(): ?int { return $this->id; }
    public function getCode(): string { return $this->code; }
    public function getName(): string { return $this->name; }
    public function getDescription(): ?string { return $this->description; }
    public function getNumberPattern(): string { return $this->numberPattern; }
    public function requiresApproval(): bool { return $this->requiresApproval; }
    public function getDefaultConfidentiality(): string { return $this->defaultConfidentiality; }
    public function getRetentionYears(): ?int { return $this->retentionYears; }
    /** @return list<string> */
    public function getRequiredFields(): array { return $this->requiredFields; }
    public function isActive(): bool { return $this->active; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function rename(string $name, ?string $description): void
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 255) {
            throw new \InvalidArgumentException('Type name is required (at most 255 characters).');
        }
        $description = $description !== null ? trim($description) : null;
        $this->name = $name;
        $this->description = $description === '' ? null : $description;
        $this->touch();
    }

    /**
     * Pattern tokens: {CODE} (type code), {YYYY} / {YY} (registration year), {UNIT} (owner unit code or "GEN"),
     * {SEQ} or {SEQ:n} (gapless counter per type and year, zero-padded to n digits). A year token and the counter are
     * required so numbers never repeat when the counter restarts each year.
     */
    public function setNumberPattern(string $pattern): void
    {
        $pattern = trim($pattern);
        if (mb_strlen($pattern) > 64 || preg_match('/^(?:[A-Za-z0-9\/._-]|\{(?:CODE|YYYY|YY|UNIT|SEQ(?::[1-9])?)\})+$/', $pattern) !== 1) {
            throw new \InvalidArgumentException('Number pattern may contain letters, digits, / . _ - and the tokens {CODE} {YYYY} {YY} {UNIT} {SEQ} {SEQ:n}.');
        }
        if (preg_match('/\{SEQ(?::[1-9])?\}/', $pattern) !== 1 || preg_match('/\{(?:YYYY|YY)\}/', $pattern) !== 1) {
            throw new \InvalidArgumentException('Number pattern must contain a year token ({YYYY} or {YY}) and the counter ({SEQ} or {SEQ:n}).');
        }
        $this->numberPattern = $pattern;
        $this->touch();
    }

    /** @param list<string> $requiredFields */
    public function configure(bool $requiresApproval, string $defaultConfidentiality, ?int $retentionYears, array $requiredFields, bool $active): void
    {
        if (!in_array($defaultConfidentiality, Document::CONFIDENTIALITY_LEVELS, true)) {
            throw new \InvalidArgumentException('Confidentiality must be general or confidential.');
        }
        if ($retentionYears !== null && ($retentionYears < 1 || $retentionYears > 100)) {
            throw new \InvalidArgumentException('Retention must be between 1 and 100 years.');
        }
        $unknown = array_diff($requiredFields, self::REQUIRABLE_FIELDS);
        if ($unknown !== []) {
            throw new \InvalidArgumentException('Unknown required field: '.implode(', ', $unknown).'.');
        }
        $this->requiresApproval = $requiresApproval;
        $this->defaultConfidentiality = $defaultConfidentiality;
        $this->retentionYears = $retentionYears;
        $this->requiredFields = array_values(array_unique($requiredFields));
        $this->active = $active;
        $this->touch();
    }

    /** Builds a registration number for the given counter value. */
    public function formatNumber(int $sequence, \DateTimeImmutable $date, ?OrgUnit $ownerUnit): string
    {
        return (string) preg_replace_callback('/\{(CODE|YYYY|YY|UNIT|SEQ)(?::([1-9]))?\}/', fn (array $m): string => match ($m[1]) {
            'CODE' => $this->code,
            'YYYY' => $date->format('Y'),
            'YY' => $date->format('y'),
            'UNIT' => $ownerUnit?->getCode() ?? 'GEN',
            'SEQ' => str_pad((string) $sequence, (int) ($m[2] ?? 1), '0', STR_PAD_LEFT),
        }, $this->numberPattern);
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
