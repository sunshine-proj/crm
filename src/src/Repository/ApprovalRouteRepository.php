<?php

namespace App\Repository;

use App\Entity\ApprovalRoute;
use App\Entity\Document;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ApprovalRoute> */
class ApprovalRouteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, ApprovalRoute::class); }

    /** @return list<ApprovalRoute> newest round first */
    public function findForDocument(Document $document): array
    {
        return $this->findBy(['document' => $document], ['round' => 'DESC']);
    }

    public function findLatest(Document $document): ?ApprovalRoute
    {
        return $this->findOneBy(['document' => $document], ['round' => 'DESC']);
    }
}
