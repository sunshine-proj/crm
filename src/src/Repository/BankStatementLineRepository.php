<?php

namespace App\Repository;

use App\Entity\BankAccount;
use App\Entity\BankStatementLine;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<BankStatementLine> */
class BankStatementLineRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, BankStatementLine::class); }

    /** @return list<BankStatementLine> */
    public function search(BankAccount $account, ?string $state, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to, int $limit): array
    {
        $query = $this->createQueryBuilder('l')->andWhere('l.bankAccount = :account')->setParameter('account', $account)
            ->orderBy('l.bookingDate', 'DESC')->addOrderBy('l.id', 'DESC')->setMaxResults($limit);
        if ($state === 'unmatched') $query->andWhere('l.matchType IS NULL');
        if ($state === 'matched') $query->andWhere("l.matchType IN ('customer_payment', 'vendor_payment')");
        if ($state === 'ignored') $query->andWhere("l.matchType = 'ignored'");
        if ($from !== null) $query->andWhere('l.bookingDate >= :from')->setParameter('from', $from, Types::DATE_IMMUTABLE);
        if ($to !== null) $query->andWhere('l.bookingDate <= :to')->setParameter('to', $to, Types::DATE_IMMUTABLE);

        return $query->getQuery()->getResult();
    }

    public function findMatchedTo(string $type, int $id): ?BankStatementLine
    {
        return $this->findOneBy(['matchType' => $type, 'matchId' => $id]);
    }

    /** @return array<string, true> dedupe keys already stored for the account */
    public function existingKeys(BankAccount $account, array $keys): array
    {
        if ($keys === []) {
            return [];
        }
        $rows = $this->createQueryBuilder('l')->select('l.dedupeKey')->andWhere('l.bankAccount = :account AND l.dedupeKey IN (:keys)')
            ->setParameter('account', $account)->setParameter('keys', $keys)->getQuery()->getScalarResult();

        return array_fill_keys(array_column($rows, 'dedupeKey'), true);
    }
}
