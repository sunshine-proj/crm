<?php

namespace App\Service\Dms;

use App\Entity\DocumentType;
use App\Entity\NumberSequence;
use App\Entity\OrgUnit;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;

/**
 * Gapless registration numbers per document type and year, on the same `number_sequence` counters as invoices
 * (series `DMS-<type code>`). A number is taken only inside the registering transaction under a row lock, so a
 * failed registration rolls the counter back and no number is skipped or used twice.
 */
final class RegistryNumbering
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /** Creates the counter row for the year before the transaction opens, so a first-of-year race cannot abort it. */
    public function prepare(DocumentType $type, \DateTimeImmutable $date): void
    {
        $connection = $this->entityManager->getConnection();
        if ($connection->isTransactionActive()) {
            return;
        }
        try {
            $connection->insert('number_sequence', ['series' => $this->series($type), 'year' => (int) $date->format('Y'), 'last_number' => 0]);
        } catch (UniqueConstraintViolationException) {
            // Another request created it first, which is all we need.
        }
    }

    public function allocate(DocumentType $type, \DateTimeImmutable $date, ?OrgUnit $ownerUnit): string
    {
        if (!$this->entityManager->getConnection()->isTransactionActive()) {
            throw new \LogicException('Registration numbers must be allocated inside the registering transaction.');
        }
        $sequence = $this->lockedSequence($type, $date);
        if ($sequence === null) {
            throw new \DomainException('The number sequence is unavailable; please try again.');
        }
        $number = $sequence->increment();
        $this->entityManager->flush();

        return $type->formatNumber($number, $date, $ownerUnit);
    }

    /** Reads the counter under a row lock, refreshing any copy already in memory so the value is the committed one. */
    private function lockedSequence(DocumentType $type, \DateTimeImmutable $date): ?NumberSequence
    {
        $result = $this->entityManager->createQueryBuilder()->select('s')->from(NumberSequence::class, 's')
            ->andWhere('s.series = :series AND s.year = :year')
            ->setParameter('series', $this->series($type))->setParameter('year', (int) $date->format('Y'))
            ->getQuery()->setLockMode(LockMode::PESSIMISTIC_WRITE)->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $result instanceof NumberSequence ? $result : null;
    }

    private function series(DocumentType $type): string
    {
        return 'DMS-'.$type->getCode();
    }
}
