<?php

namespace App\Repository;

use App\Entity\OrganizationModule;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<OrganizationModule> */
class OrganizationModuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, OrganizationModule::class); }

    /** @return array<string, OrganizationModule> stored configuration by module key */
    public function allByKey(): array
    {
        $byKey = [];
        foreach ($this->findAll() as $module) {
            $byKey[$module->getKey()] = $module;
        }

        return $byKey;
    }
}
