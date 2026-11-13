<?php

namespace App\Repository;

use App\Entity\Document;
use App\Entity\DocumentLink;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<DocumentLink> */
class DocumentLinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, DocumentLink::class); }

    /** @return list<DocumentLink> links from or to the document */
    public function findInvolving(Document $document): array
    {
        return $this->createQueryBuilder('link')
            ->addSelect('source', 'target')->join('link.source', 'source')->join('link.target', 'target')
            ->andWhere('link.source = :document OR link.target = :document')->setParameter('document', $document)
            ->orderBy('link.createdAt', 'ASC')->addOrderBy('link.id', 'ASC')
            ->getQuery()->getResult();
    }

    public function exists(Document $source, Document $target, string $kind): bool
    {
        return $this->findOneBy(['source' => $source, 'target' => $target, 'kind' => $kind]) !== null;
    }
}
