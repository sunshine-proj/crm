<?php

namespace App\Repository;

use App\Entity\Document;
use App\Entity\DocumentVersion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<DocumentVersion> */
class DocumentVersionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, DocumentVersion::class); }

    /** @return list<DocumentVersion> newest first */
    public function findForDocument(Document $document): array
    {
        return $this->findBy(['document' => $document], ['number' => 'DESC']);
    }

    public function findLatest(Document $document): ?DocumentVersion
    {
        return $this->findOneBy(['document' => $document], ['number' => 'DESC']);
    }

    /**
     * @param list<Document> $documents
     * @return array<int, DocumentVersion> document id => current version
     */
    public function findLatestFor(array $documents): array
    {
        if ($documents === []) {
            return [];
        }
        $versions = $this->createQueryBuilder('version')
            ->andWhere('version.document IN (:documents)')->setParameter('documents', $documents)
            ->orderBy('version.number', 'ASC')->getQuery()->getResult();
        $latest = [];
        foreach ($versions as $version) {
            $latest[(int) $version->getDocument()->getId()] = $version;
        }

        return $latest;
    }
}
