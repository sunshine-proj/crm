<?php

namespace App\Repository;

use App\Entity\Document;
use App\Entity\DocumentEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<DocumentEvent> */
class DocumentEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, DocumentEvent::class); }

    /** @return list<DocumentEvent> oldest first */
    public function findForDocument(Document $document): array
    {
        return $this->findBy(['document' => $document], ['createdAt' => 'ASC', 'id' => 'ASC']);
    }
}
