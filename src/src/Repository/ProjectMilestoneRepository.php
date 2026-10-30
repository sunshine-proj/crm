<?php

namespace App\Repository;

use App\Entity\ProjectMilestone;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ProjectMilestoneRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProjectMilestone::class);
    }

    /** @return list<ProjectMilestone> */
    public function findForProject(int $projectId): array
    {
        return $this->createQueryBuilder('milestone')
            ->andWhere('IDENTITY(milestone.project) = :projectId')
            ->setParameter('projectId', $projectId)
            ->orderBy('milestone.dueDate', 'ASC')
            ->addOrderBy('milestone.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
