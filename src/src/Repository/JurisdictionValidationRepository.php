<?php

namespace App\Repository;

use App\Entity\JurisdictionValidation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<JurisdictionValidation> */
class JurisdictionValidationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, JurisdictionValidation::class); }

    public function findActive(string $countryCode, string $scope): ?JurisdictionValidation
    {
        return $this->findOneBy(['countryCode' => $countryCode, 'scope' => $scope, 'revokedAt' => null], ['id' => 'DESC']);
    }

    /** @return list<JurisdictionValidation> */
    public function history(): array
    {
        return $this->findBy([], ['id' => 'DESC'], 100);
    }
}
