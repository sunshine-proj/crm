<?php

namespace App\Repository;

use App\Entity\Document;
use App\Entity\DocumentComment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<DocumentComment> */
class DocumentCommentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, DocumentComment::class); }

    /** @return list<DocumentComment> newest version first, then oldest comment first */
    public function findForDocument(Document $document): array
    {
        return $this->findBy(['document' => $document], ['versionNumber' => 'DESC', 'createdAt' => 'ASC', 'id' => 'ASC']);
    }
}
