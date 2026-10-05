<?php

namespace App\Repository;

use App\Entity\PayableBill;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<PayableBill> */
class PayableBillRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PayableBill::class);
    }

    /** @return list<PayableBill> */
    public function findRecent(): array
    {
        return $this->findBy([], ['billDate' => 'DESC', 'id' => 'DESC']);
    }
}