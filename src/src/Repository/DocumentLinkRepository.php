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

    /**
     * "Replaces" links whose source is in force while the replaced document is still registered or in force: the
     * replaced side has to be superseded. Restricted to one source when given.
     *
     * @return list<DocumentLink>
     */
    public function findPendingSupersessions(?Document $source = null): array
    {
        $query = $this->createQueryBuilder('link')
            ->addSelect('source', 'target')->join('link.source', 'source')->join('link.target', 'target')
            ->andWhere('link.kind = :replaces')->setParameter('replaces', 'replaces')
            ->andWhere('source.status = :inForce')->setParameter('inForce', Document::IN_FORCE)
            ->andWhere('target.status IN (:current)')->setParameter('current', [Document::REGISTERED, Document::IN_FORCE])
            ->orderBy('link.id', 'ASC');
        if ($source !== null) {
            $query->andWhere('link.source = :source')->setParameter('source', $source);
        }

        return $query->getQuery()->getResult();
    }
}
