<?php

namespace App\Repository;

use App\Dto\DocumentSearch;
use App\Entity\ApprovalStep;
use App\Entity\Document;
use App\Entity\OrgUnit;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Document> */
class DocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, Document::class); }

    /**
     * Documents matching the filters that the viewer may read. A null viewer means unrestricted (registry clerks).
     * The visibility predicate mirrors DocumentAccess::canRead().
     *
     * @param list<int> $managedUnitIds units the viewer heads (with sub-units)
     * @param list<int> $actingForIds the viewer and the people they deputize for (approval participation)
     * @return array{items: list<Document>, total: int}
     */
    public function search(DocumentSearch $search, ?User $viewer, array $managedUnitIds, array $actingForIds = []): array
    {
        $query = $this->createQueryBuilder('document')
            ->addSelect('type')->join('document.type', 'type')
            ->orderBy('document.updatedAt', 'DESC')->addOrderBy('document.id', 'DESC');
        if ($viewer !== null) {
            $this->restrictTo($query, $viewer, $managedUnitIds, $actingForIds);
        }
        $this->applyFilters($query, $search);
        $query->setFirstResult($search->offset)->setMaxResults($search->limit);
        $paginator = new Paginator($query->getQuery(), fetchJoinCollection: false);

        return ['items' => array_values(iterator_to_array($paginator)), 'total' => count($paginator)];
    }

    public function countForOwnerUnit(OrgUnit $unit): int
    {
        return (int) $this->createQueryBuilder('document')->select('COUNT(document.id)')
            ->andWhere('document.ownerUnit = :unit')->setParameter('unit', $unit)->getQuery()->getSingleScalarResult();
    }

    /**
     * @param list<int> $managedUnitIds
     * @param list<int> $actingForIds
     */
    private function restrictTo(QueryBuilder $query, User $viewer, array $managedUnitIds, array $actingForIds): void
    {
        $participant = $this->getEntityManager()->createQueryBuilder()->select('1')->from(ApprovalStep::class, 'participation')
            ->join('participation.stage', 'participationStage')->join('participationStage.route', 'participationRoute')
            ->where('participationRoute.document = document')->andWhere('IDENTITY(participation.assignee) IN (:actingFor)');
        $visible = $query->expr()->orX(
            'document.author = :viewer',
            ':viewer MEMBER OF document.readers',
            $query->expr()->andX('document.confidentiality = :general', 'document.status IN (:published)'),
            $query->expr()->exists($participant->getDQL()),
        );
        $query->setParameter('actingFor', $actingForIds !== [] ? $actingForIds : [(int) $viewer->getId()]);
        if ($managedUnitIds !== []) {
            $visible->add($query->expr()->andX('document.confidentiality = :general', 'IDENTITY(document.ownerUnit) IN (:managed)'));
            $query->setParameter('managed', $managedUnitIds);
        }
        $query->andWhere($visible)
            ->setParameter('viewer', $viewer)
            ->setParameter('general', Document::GENERAL)
            ->setParameter('published', Document::REGISTERED_STATES);
    }

    private function applyFilters(QueryBuilder $query, DocumentSearch $search): void
    {
        foreach (preg_split('/\s+/u', mb_strtolower(trim((string) $search->text)), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $index => $word) {
            $query->andWhere("document.searchText LIKE :word$index ESCAPE '!'")
                ->setParameter("word$index", '%'.self::likeLiteral($word).'%');
        }
        $equal = ['typeId' => 'IDENTITY(document.type)', 'ownerUnitId' => 'IDENTITY(document.ownerUnit)', 'authorId' => 'IDENTITY(document.author)',
            'clientId' => 'IDENTITY(document.client)', 'projectId' => 'IDENTITY(document.project)', 'status' => 'document.status'];
        foreach ($equal as $property => $expression) {
            if ($search->$property !== null) {
                $query->andWhere("$expression = :$property")->setParameter($property, $search->$property);
            }
        }
        if ($search->tag !== null) {
            // Exact tag match on the `[tag:<tag>]` marker kept in the search text (JSON columns are not portably searchable).
            $query->andWhere("document.searchText LIKE :tagText ESCAPE '!'")->setParameter('tagText', '%'.self::likeLiteral('[tag:'.mb_strtolower(trim($search->tag)).']').'%');
        }
        if ($search->from !== null) {
            $query->andWhere('document.createdAt >= :from')->setParameter('from', $search->from->setTime(0, 0));
        }
        if ($search->to !== null) {
            $query->andWhere('document.createdAt < :to')->setParameter('to', $search->to->setTime(0, 0)->modify('+1 day'));
        }
    }

    /** Escapes LIKE wildcards with the `!` escape character declared in the queries. */
    private static function likeLiteral(string $value): string
    {
        return strtr($value, ['!' => '!!', '%' => '!%', '_' => '!_']);
    }
}
