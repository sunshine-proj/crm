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

    /**
     * @param list<string> $hiddenSubjectTypes subject types of modules the user cannot open
     * @return list<Notification>
     */
    public function inbox(User $user, bool $unreadOnly, int $limit, array $hiddenSubjectTypes = []): array
    {
        $query = $this->createQueryBuilder('n')->andWhere('n.user = :user')->setParameter('user', $user)
            ->orderBy('n.createdAt', 'DESC')->addOrderBy('n.id', 'DESC')->setMaxResults($limit);
        if ($unreadOnly) {
            $query->andWhere('n.readAt IS NULL');
        }
        if ($hiddenSubjectTypes !== []) {
            $query->andWhere('n.subjectType NOT IN (:hidden)')->setParameter('hidden', $hiddenSubjectTypes);
        }

        return $query->getQuery()->getResult();
    }

    /** @param list<string> $hiddenSubjectTypes */
    public function unreadCount(User $user, array $hiddenSubjectTypes = []): int
    {
        $query = $this->createQueryBuilder('n')->select('COUNT(n.id)')->andWhere('n.user = :user')->andWhere('n.readAt IS NULL')->setParameter('user', $user);
        if ($hiddenSubjectTypes !== []) {
            $query->andWhere('n.subjectType NOT IN (:hidden)')->setParameter('hidden', $hiddenSubjectTypes);
        }

        return (int) $query->getQuery()->getSingleScalarResult();
    }
}
