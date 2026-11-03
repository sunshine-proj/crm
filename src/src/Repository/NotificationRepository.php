<?php

namespace App\Repository;

use App\Entity\Notification;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Notification> */
class NotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, Notification::class); }

    public function exists(User $user, string $dedupeKey): bool
    {
        return $this->count(['user' => $user, 'dedupeKey' => $dedupeKey]) > 0;
    }

    /** @return list<Notification> */
    public function inbox(User $user, bool $unreadOnly, int $limit): array
    {
        $query = $this->createQueryBuilder('n')->andWhere('n.user = :user')->setParameter('user', $user)
            ->orderBy('n.createdAt', 'DESC')->addOrderBy('n.id', 'DESC')->setMaxResults($limit);
        if ($unreadOnly) {
            $query->andWhere('n.readAt IS NULL');
        }

        return $query->getQuery()->getResult();
    }

    public function unreadCount(User $user): int
    {
        return $this->count(['user' => $user, 'readAt' => null]);
    }
}
