<?php

namespace App\Repository;

use App\Entity\Document;
use App\Entity\DocumentDisposal;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<DocumentDisposal> */
class DocumentDisposalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, DocumentDisposal::class); }

    public function findOpenFor(Document $document): ?DocumentDisposal
    {
        return $this->findOneBy(['document' => $document, 'status' => DocumentDisposal::PROPOSED]);
    }

    /** @return list<DocumentDisposal> newest first */
    public function findForDocument(Document $document): array
    {
        return $this->findBy(['document' => $document], ['proposedAt' => 'DESC', 'id' => 'DESC']);
    }

    /** @return list<DocumentDisposal> newest first */
    public function findRecent(?string $status, int $limit = 200): array
    {
        return $this->findBy($status !== null ? ['status' => $status] : [], ['proposedAt' => 'DESC', 'id' => 'DESC'], $limit);
    }
}
