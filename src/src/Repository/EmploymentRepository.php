<?php

namespace App\Repository;

use App\Entity\Employment;
use App\Entity\Position;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Employment> */
class EmploymentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, Employment::class); }

    /** @return list<Employment> employments in force on the given day, with position and unit loaded */
    public function findActiveOn(\DateTimeImmutable $day): array
    {
        return $this->withStructure()
            ->andWhere('employment.startsOn <= :day')
            ->andWhere('employment.endsOn IS NULL OR employment.endsOn >= :day')
            ->setParameter('day', $day->setTime(0, 0), 'date_immutable')
            ->getQuery()->getResult();
    }

    /** @return list<Employment> */
    public function search(?int $unitId, ?int $userId, bool $includeEnded, \DateTimeImmutable $today): array
    {
        $query = $this->withStructure();
        if ($unitId !== null) {
            $query->andWhere('IDENTITY(position.unit) = :unit')->setParameter('unit', $unitId);
        }
        if ($userId !== null) {
            $query->andWhere('IDENTITY(employment.user) = :user')->setParameter('user', $userId);
        }
        if (!$includeEnded) {
            $query->andWhere('employment.endsOn IS NULL OR employment.endsOn >= :today')->setParameter('today', $today->setTime(0, 0), 'date_immutable');
        }

        return $query->getQuery()->getResult();
    }

    /** @return list<Employment> employments of the user or on the position whose date range meets [start, end] */
    public function findOverlapping(?User $user, ?Position $position, \DateTimeImmutable $startsOn, ?\DateTimeImmutable $endsOn, ?Employment $except = null): array
    {
        $query = $this->createQueryBuilder('employment')
            ->andWhere('employment.endsOn IS NULL OR employment.endsOn >= :start')->setParameter('start', $startsOn->setTime(0, 0), 'date_immutable');
        if ($endsOn !== null) {
            $query->andWhere('employment.startsOn <= :end')->setParameter('end', $endsOn->setTime(0, 0), 'date_immutable');
        }
        if ($user !== null) {
            $query->andWhere('employment.user = :user')->setParameter('user', $user);
        }
        if ($position !== null) {
            $query->andWhere('employment.position = :position')->setParameter('position', $position);
        }
        if ($except?->getId() !== null) {
            $query->andWhere('employment.id <> :except')->setParameter('except', $except->getId());
        }

        return $query->getQuery()->getResult();
    }

    public function countForPosition(Position $position, ?\DateTimeImmutable $activeFrom = null): int
    {
        $query = $this->createQueryBuilder('employment')->select('COUNT(employment.id)')
            ->andWhere('employment.position = :position')->setParameter('position', $position);
        if ($activeFrom !== null) {
            $query->andWhere('employment.endsOn IS NULL OR employment.endsOn >= :from')->setParameter('from', $activeFrom->setTime(0, 0), 'date_immutable');
        }

        return (int) $query->getQuery()->getSingleScalarResult();
    }

    private function withStructure(): QueryBuilder
    {
        return $this->createQueryBuilder('employment')
            ->addSelect('position', 'unit', 'user')
            ->join('employment.position', 'position')
            ->join('position.unit', 'unit')
            ->join('employment.user', 'user')
            ->orderBy('position.isHead', 'DESC')->addOrderBy('user.email', 'ASC')->addOrderBy('employment.id', 'ASC');
    }
}
