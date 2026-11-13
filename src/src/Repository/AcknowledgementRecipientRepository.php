<?php

namespace App\Repository;

use App\Entity\AcknowledgementRecipient;
use App\Entity\AcknowledgementRequest;
use App\Entity\Document;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AcknowledgementRecipient> */
class AcknowledgementRecipientRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, AcknowledgementRecipient::class); }

    /** @return list<AcknowledgementRecipient> ordered by unit name (people without a unit last), then e-mail */
    public function findForRequest(AcknowledgementRequest $request): array
    {
        $rows = $this->createQueryBuilder('recipient')
            ->andWhere('recipient.request = :request')->setParameter('request', $request)
            ->getQuery()->getResult();
        // Sorted here because databases disagree on where NULL unit names go.
        usort($rows, static fn (AcknowledgementRecipient $a, AcknowledgementRecipient $b): int => [$a->getUnitName() === null, $a->getUnitName(), $a->getUserEmail()] <=> [$b->getUnitName() === null, $b->getUnitName(), $b->getUserEmail()]);

        return $rows;
    }

    /**
     * @param list<AcknowledgementRequest> $requests
     * @return array<int, list<AcknowledgementRecipient>> request id => recipients
     */
    public function findForRequests(array $requests): array
    {
        if ($requests === []) {
            return [];
        }
        $grouped = [];
        foreach ($this->createQueryBuilder('recipient')->andWhere('recipient.request IN (:requests)')->setParameter('requests', $requests)->getQuery()->getResult() as $recipient) {
            $grouped[(int) $recipient->getRequest()->getId()][] = $recipient;
        }

        return $grouped;
    }

    /** @return array<int, true> user ids already on the request */
    public function userIdsOn(AcknowledgementRequest $request): array
    {
        $ids = $this->createQueryBuilder('recipient')->select('IDENTITY(recipient.user) AS id')
            ->andWhere('recipient.request = :request')->setParameter('request', $request)
            ->getQuery()->getSingleColumnResult();

        return array_fill_keys(array_map('intval', $ids), true);
    }

    /** @return list<AcknowledgementRecipient> what the user still has to acknowledge, earliest deadline first */
    public function findOpenFor(User $user): array
    {
        return $this->withRequest()
            ->andWhere('recipient.user = :user')->setParameter('user', $user)
            ->andWhere('recipient.status IN (:owing)')->setParameter('owing', AcknowledgementRecipient::OPEN_STATES)
            ->andWhere('request.status = :open')->setParameter('open', AcknowledgementRequest::OPEN)
            ->orderBy('request.dueOn', 'ASC')->addOrderBy('request.id', 'ASC')
            ->getQuery()->getResult();
    }

    /** @return list<AcknowledgementRecipient> every request the user was asked to acknowledge, newest first */
    public function findHistoryFor(User $user, int $limit = 200): array
    {
        return $this->withRequest()
            ->andWhere('recipient.user = :user')->setParameter('user', $user)
            ->orderBy('request.createdAt', 'DESC')->addOrderBy('request.id', 'DESC')->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /** @return list<AcknowledgementRecipient> the user's rows on open requests for this document */
    public function findOpenRequestRowsFor(Document $document, User $user): array
    {
        return $this->withRequest()
            ->andWhere('request.document = :document')->setParameter('document', $document)
            ->andWhere('recipient.user = :user')->setParameter('user', $user)
            ->andWhere('request.status = :open')->setParameter('open', AcknowledgementRequest::OPEN)
            ->getQuery()->getResult();
    }

    /** @return list<AcknowledgementRecipient> the user's rows on every request for this document, newest first */
    public function findForDocumentAndUser(Document $document, User $user): array
    {
        return $this->withRequest()
            ->andWhere('request.document = :document')->setParameter('document', $document)
            ->andWhere('recipient.user = :user')->setParameter('user', $user)
            ->orderBy('request.createdAt', 'DESC')->addOrderBy('request.id', 'DESC')
            ->getQuery()->getResult();
    }

    public function isRecipient(Document $document, User $user): bool
    {
        if ($document->getId() === null) {
            return false;
        }

        return (int) $this->createQueryBuilder('recipient')->select('COUNT(recipient.id)')->join('recipient.request', 'request')
            ->andWhere('request.document = :document')->setParameter('document', $document)
            ->andWhere('recipient.user = :user')->setParameter('user', $user)
            ->getQuery()->getSingleScalarResult() > 0;
    }

    public function countOpen(AcknowledgementRequest $request): int
    {
        return (int) $this->createQueryBuilder('recipient')->select('COUNT(recipient.id)')
            ->andWhere('recipient.request = :request')->setParameter('request', $request)
            ->andWhere('recipient.status IN (:owing)')->setParameter('owing', AcknowledgementRecipient::OPEN_STATES)
            ->getQuery()->getSingleScalarResult();
    }

    private function withRequest(): QueryBuilder
    {
        return $this->createQueryBuilder('recipient')
            ->addSelect('request', 'document')
            ->join('recipient.request', 'request')->join('request.document', 'document');
    }
}
