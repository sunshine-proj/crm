<?php

namespace App\Repository;

use App\Entity\ApprovalRoute;
use App\Entity\ApprovalStage;
use App\Entity\ApprovalStep;
use App\Entity\Document;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ApprovalStep> */
class ApprovalStepRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, ApprovalStep::class); }

    /**
     * Open steps of running approvals assigned to any of the users (the viewer and the people they deputize for).
     *
     * @param list<int> $userIds
     * @return list<ApprovalStep> earliest due first
     */
    public function findOpenFor(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return $this->open()
            ->andWhere('IDENTITY(step.assignee) IN (:users)')->setParameter('users', $userIds)
            ->orderBy('step.dueOn', 'ASC')->addOrderBy('step.id', 'ASC')
            ->getQuery()->getResult();
    }

    /** @return list<ApprovalStep> every open step of a running approval that has a due date */
    public function findOpenWithDueDate(): array
    {
        return $this->open()->andWhere('step.dueOn IS NOT NULL')->getQuery()->getResult();
    }

    /** @param list<int> $userIds */
    public function isParticipant(Document $document, array $userIds): bool
    {
        if ($userIds === [] || $document->getId() === null) {
            return false;
        }

        return (int) $this->createQueryBuilder('step')->select('COUNT(step.id)')
            ->join('step.stage', 'stage')->join('stage.route', 'route')
            ->andWhere('route.document = :document')->setParameter('document', $document)
            ->andWhere('IDENTITY(step.assignee) IN (:users)')->setParameter('users', $userIds)
            ->getQuery()->getSingleScalarResult() > 0;
    }

    private function open(): QueryBuilder
    {
        return $this->createQueryBuilder('step')
            ->addSelect('stage', 'route', 'document')
            ->join('step.stage', 'stage')->join('stage.route', 'route')->join('route.document', 'document')
            ->andWhere('step.status = :pending')->setParameter('pending', ApprovalStep::PENDING)
            ->andWhere('stage.status = :active')->setParameter('active', ApprovalStage::ACTIVE)
            ->andWhere('route.status = :running')->setParameter('running', ApprovalRoute::ACTIVE);
    }
}
