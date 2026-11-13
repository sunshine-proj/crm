<?php

namespace App\Repository;

use App\Entity\Document;
use App\Entity\DocumentAttachment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<DocumentAttachment> */
class DocumentAttachmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, DocumentAttachment::class); }

    /** @return list<DocumentAttachment> oldest first */
    public function findForDocument(Document $document): array
    {
        return $this->findBy(['document' => $document], ['createdAt' => 'ASC', 'id' => 'ASC']);
    }
}
