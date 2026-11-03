<?php

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;

/** Converts tracked entities to JSON-safe state arrays and applies such arrays back. */
final class RecordSnapshotter
{
    /** Associations that are never rolled back because another workflow owns them. */
    public const PROTECTED_ASSOCIATIONS = ['invoice'];

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /** @return array<string, mixed> scalar fields by name; owning single-valued associations as `<name>` => id */
    public function snapshot(object $entity): array
    {
        $metadata = $this->entityManager->getClassMetadata($entity::class);
        $state = [];
        foreach ($metadata->getFieldNames() as $field) {
            if (in_array($field, $metadata->getIdentifierFieldNames(), true)) {
                continue;
            }
            $state[$field] = $this->normalize($metadata->getFieldValue($entity, $field), $metadata->getTypeOfField($field));
        }
        foreach ($metadata->getAssociationNames() as $name) {
            if (!$metadata->isSingleValuedAssociation($name) || $metadata->isAssociationInverseSide($name)) {
                continue;
            }
            $related = $metadata->getFieldValue($entity, $name);
            $state[$name] = $related === null ? null : $this->entityManager->getUnitOfWork()->getSingleIdentifierValue($related);
        }

        return $state;
    }

    /**
     * Sets state on an entity. Throws \DomainException when a referenced record no longer exists.
     *
     * @param array<string, mixed> $state
     * @param list<string> $skipFields
     */
    public function apply(object $entity, array $state, array $skipFields = []): void
    {
        $metadata = $this->entityManager->getClassMetadata($entity::class);
        foreach ($state as $name => $value) {
            if (in_array($name, $skipFields, true)) {
                continue;
            }
            if (in_array($name, $metadata->getFieldNames(), true)) {
                $metadata->setFieldValue($entity, $name, $this->denormalize($metadata, $name, $value));
                continue;
            }
            if (!in_array($name, $metadata->getAssociationNames(), true) || in_array($name, self::PROTECTED_ASSOCIATIONS, true)) {
                continue;
            }
            $target = $metadata->getAssociationTargetClass($name);
            $related = $value === null ? null : $this->entityManager->find($target, $value);
            if ($value !== null && $related === null) {
                throw new \DomainException(sprintf('Cannot restore: the referenced %s #%s no longer exists.', $name, (string) $value));
            }
            $metadata->setFieldValue($entity, $name, $related);
        }
    }

    private function normalize(mixed $value, ?string $type): mixed
    {
        return match (true) {
            $value instanceof \BackedEnum => $value->value,
            $value instanceof \DateTimeInterface => $value->format(in_array($type, ['date', 'date_immutable'], true) ? 'Y-m-d' : \DateTimeInterface::ATOM),
            default => $value,
        };
    }

    private function denormalize(ClassMetadata $metadata, string $field, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        $enum = $metadata->getFieldMapping($field)->enumType ?? null;
        if ($enum !== null) {
            return $enum::from($value);
        }

        return match ($metadata->getTypeOfField($field)) {
            'datetime_immutable', 'datetimetz_immutable', 'date_immutable', 'time_immutable' => new \DateTimeImmutable((string) $value),
            'datetime', 'datetimetz', 'date', 'time' => new \DateTime((string) $value),
            default => $value,
        };
    }
}
