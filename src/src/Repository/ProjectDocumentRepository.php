<?php

namespace App\Repository;

use App\Entity\ProjectDocument;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ProjectDocument> */
class ProjectDocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, ProjectDocument::class); }

    /** @return list<ProjectDocument> */
    public function findForProject(int $projectId): array
    {
        return $this->createQueryBuilder('document')
            ->leftJoin('document.uploadedBy', 'uploader')->addSelect('uploader')
            ->andWhere('IDENTITY(document.project) = :projectId')
            ->setParameter('projectId', $projectId)
            ->orderBy('document.createdAt', 'DESC')
            ->getQuery()->getResult();
    }
}