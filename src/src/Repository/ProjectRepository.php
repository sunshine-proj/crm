<?php

namespace App\Repository;

use App\Entity\Project;
use App\Entity\ProjectStatus;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ProjectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Project::class);
    }

    public function countWithStatus(ProjectStatus $status): int
    {
        return (int) $this->createQueryBuilder('project')
            ->select('COUNT(project.id)')
            ->where('project.status = :status')
            ->setParameter('status', $status->value)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return list<int> ids of the projects the user manages */
    public function findIdsManagedBy(User $user): array
    {
        return array_map('intval', $this->createQueryBuilder('project')
            ->select('project.id')
            ->where('project.manager = :manager')
            ->setParameter('manager', $user)
            ->getQuery()
            ->getSingleColumnResult());
    }
}
