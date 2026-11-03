<?php

namespace App\Repository;

use App\Entity\ReminderPreference;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ReminderPreference> */
class ReminderPreferenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, ReminderPreference::class); }

    public function findForUser(User $user): ?ReminderPreference
    {
        return $this->findOneBy(['user' => $user]);
    }
}
