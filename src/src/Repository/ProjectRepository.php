<?php

namespace App\Repository;

use App\Entity\Project;
use App\Entity\ProjectStatus;
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
}