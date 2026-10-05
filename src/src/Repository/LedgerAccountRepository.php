<?php

namespace App\Repository;

use App\Entity\LedgerAccount;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<LedgerAccount> */
class LedgerAccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LedgerAccount::class);
    }

    /** @return list<LedgerAccount> */
    public function findActiveOrdered(): array
    {
        return $this->findBy(['active' => true], ['code' => 'ASC']);
    }

    public function hasJournalLines(int $accountId): bool
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(line.id)')
            ->from(\App\Entity\JournalEntryLine::class, 'line')
            ->andWhere('line.account = :accountId')
            ->setParameter('accountId', $accountId)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }
}