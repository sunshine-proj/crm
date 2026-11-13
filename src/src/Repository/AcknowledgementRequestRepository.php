<?php

namespace App\Repository;

use App\Entity\AcknowledgementRecipient;
use App\Entity\AcknowledgementRequest;
use App\Entity\Document;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AcknowledgementRequest> */
class AcknowledgementRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, AcknowledgementRequest::class); }

    /** @return list<AcknowledgementRequest> newest first */
    public function findForDocument(Document $document): array
    {
        return $this->findBy(['document' => $document], ['createdAt' => 'DESC', 'id' => 'DESC']);
    }

    /** @return list<AcknowledgementRequest> open requests that take in people who join a targeted unit or position */
    public function findOpenAddingJoiners(): array
    {
        return $this->findBy(['status' => AcknowledgementRequest::OPEN, 'autoAddJoiners' => true], ['id' => 'ASC']);
    }

    /** @return list<AcknowledgementRequest> requests sent in [from, to) */
    public function findCreatedBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('request')
            ->andWhere('request.createdAt >= :from')->setParameter('from', $from)
            ->andWhere('request.createdAt < :to')->setParameter('to', $to)
            ->getQuery()->getResult();
    }

    /** @return list<AcknowledgementRequest> open requests with a deadline */
    public function findOpenWithDueDate(): array
    {
        return $this->createQueryBuilder('request')->addSelect('document')->join('request.document', 'document')
            ->andWhere('request.status = :open')->setParameter('open', AcknowledgementRequest::OPEN)
            ->andWhere('request.dueOn IS NOT NULL')
            ->getQuery()->getResult();
    }

    /**
     * Requests the viewer may monitor: everything for clerks ($viewer null), otherwise the ones they sent and the
     * ones with a recipient in a unit they manage.
     *
     * @param list<int> $managedUnitIds
     * @return list<AcknowledgementRequest> newest first
     */
    public function findMonitored(?User $viewer, array $managedUnitIds, ?string $status, int $limit = 200): array
    {
        $query = $this->createQueryBuilder('request')->addSelect('document')->join('request.document', 'document')
            ->orderBy('request.createdAt', 'DESC')->addOrderBy('request.id', 'DESC')->setMaxResults($limit);
        if ($viewer !== null) {
            $visible = $query->expr()->orX('request.sender = :viewer');
            if ($managedUnitIds !== []) {
                $affected = $this->getEntityManager()->createQueryBuilder()->select('1')->from(AcknowledgementRecipient::class, 'affected')
                    ->where('affected.request = request')->andWhere('IDENTITY(affected.unit) IN (:managed)');
                $visible->add($query->expr()->exists($affected->getDQL()));
                $query->setParameter('managed', $managedUnitIds);
            }
            $query->andWhere($visible)->setParameter('viewer', $viewer);
        }
        if ($status !== null) {
            $query->andWhere('request.status = :status')->setParameter('status', $status);
        }

        return $query->getQuery()->getResult();
    }
}
