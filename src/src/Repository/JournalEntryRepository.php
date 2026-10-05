<?php

namespace App\Repository;

use App\Entity\JournalEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<JournalEntry> */
class JournalEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, JournalEntry::class);
    }

    /** @return list<JournalEntry> */
    public function findPosted(?\DateTimeImmutable $from, ?\DateTimeImmutable $to, ?string $currency, ?int $accountId = null): array
    {
        $query = $this->createQueryBuilder('entry')
            ->leftJoin('entry.lines', 'line')->addSelect('line')
            ->leftJoin('line.account', 'account')->addSelect('account')
            ->orderBy('entry.entryDate', 'ASC')->addOrderBy('entry.id', 'ASC');
        if ($from !== null) {
            $query->andWhere('entry.entryDate >= :from')->setParameter('from', $from, Types::DATE_IMMUTABLE);
        }
        if ($to !== null) {
            $query->andWhere('entry.entryDate <= :to')->setParameter('to', $to, Types::DATE_IMMUTABLE);
        }
        if ($currency !== null) {
            $query->andWhere('entry.currency = :currency')->setParameter('currency', $currency);
        }
        if ($accountId !== null) {
            $query->andWhere('account.id = :accountId')->setParameter('accountId', $accountId);
        }
        return $query->getQuery()->getResult();
    }
}