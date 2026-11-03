<?php

namespace App\Service;

use App\Entity\NumberSequence;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Gapless sequential numbers per series and year for issued invoices and credit notes, used once the country's
 * numbering rules have been signed off. A number is allocated only at issue, inside the issuing transaction, under a
 * row lock: if issuing fails and rolls back, the counter rolls back with it and no number is skipped. Drafts carry a
 * DRAFT-<id> reference that never enters the sequence.
 *
 * Legacy ID-based numbers (INV-2026-000012) keep working; compliant numbers add the country code
 * (INV-LV-2026-000001) so the two formats can never collide.
 */
final class DocumentNumbering
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly JurisdictionGate $gate,
    ) {
    }

    public function isSequential(): bool
    {
        return $this->gate->numberingActive();
    }

    /** Reference for a draft or for any document while sequential numbering is off. */
    public function provisional(string $prefix, \DateTimeImmutable $date, int $id): string
    {
        return $this->isSequential() && $prefix === 'INV'
            ? sprintf('DRAFT-%06d', $id)
            : sprintf('%s-%s-%06d', $prefix, $date->format('Y'), $id);
    }

    /**
     * Makes sure the counter row for the series and year exists. Call it BEFORE opening the issuing transaction: if two
     * requests race to create the very first row of a year, the loser's insert fails harmlessly here instead of
     * aborting the issuing transaction (PostgreSQL cannot continue a transaction after a unique violation).
     */
    public function prepare(string $prefix, \DateTimeImmutable $date): void
    {
        $country = $this->gate->country();
        if ($country === null || !$this->isSequential() || $this->entityManager->getConnection()->isTransactionActive()) {
            return;
        }
        try {
            $this->entityManager->getConnection()->insert('number_sequence', ['series' => $prefix.'-'.$country, 'year' => (int) $date->format('Y'), 'last_number' => 0]);
        } catch (UniqueConstraintViolationException) {
            // The row already exists, which is what we want.
        }
    }

    /** @throws \LogicException outside a transaction; \DomainException when no country is configured */
    public function allocate(string $prefix, \DateTimeImmutable $date): string
    {
        if (!$this->entityManager->getConnection()->isTransactionActive()) {
            throw new \LogicException('Document numbers must be allocated inside the issuing transaction.');
        }
        $country = $this->gate->country();
        if ($country === null) {
            throw new \DomainException('Select the organisation country before issuing numbered documents.');
        }
        $series = $prefix.'-'.$country;
        $year = (int) $date->format('Y');

        $sequence = $this->lock($series, $year);
        if ($sequence === null) {
            try {
                $this->entityManager->persist(new NumberSequence($series, $year));
                $this->entityManager->flush();
            } catch (UniqueConstraintViolationException) {
                throw new \DomainException('Another document was being numbered at the same moment; please try again.');
            }
            $sequence = $this->lock($series, $year);
        }
        if (!$sequence instanceof NumberSequence) {
            throw new \DomainException('The number sequence is unavailable; please try again.');
        }
        $number = $sequence->increment();
        $this->entityManager->flush();

        return sprintf('%s-%d-%06d', $series, $year, $number);
    }

    private function lock(string $series, int $year): ?NumberSequence
    {
        $result = $this->entityManager->createQueryBuilder()->select('s')->from(NumberSequence::class, 's')
            ->andWhere('s.series = :series AND s.year = :year')->setParameter('series', $series)->setParameter('year', $year)
            ->getQuery()->setLockMode(LockMode::PESSIMISTIC_WRITE)->getOneOrNullResult();

        return $result instanceof NumberSequence ? $result : null;
    }
}
