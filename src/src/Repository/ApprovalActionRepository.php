<?php

namespace App\Repository;

use App\Entity\ApprovalAction;
use App\Entity\ApprovalRoute;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ApprovalAction> */
class ApprovalActionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, ApprovalAction::class); }

    /**
     * @param list<ApprovalRoute> $routes
     * @return array<int, list<ApprovalAction>> route id => actions, oldest first
     */
    public function findForRoutes(array $routes): array
    {
        if ($routes === []) {
            return [];
        }
        $grouped = [];
        foreach ($this->createQueryBuilder('action')->andWhere('action.route IN (:routes)')->setParameter('routes', $routes)
            ->orderBy('action.createdAt', 'ASC')->addOrderBy('action.id', 'ASC')->getQuery()->getResult() as $action) {
            $grouped[(int) $action->getRoute()->getId()][] = $action;
        }

        return $grouped;
    }
}
