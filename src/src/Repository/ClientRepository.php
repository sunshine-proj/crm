<?php

namespace App\Repository;

use App\Entity\Client;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ClientRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Client::class);
    }

    /**
     * @param bool $includeTaxIdentifiers searching registration/VAT numbers and billing e-mail is limited to finance users
     * @return list<Client>
     */
    public function search(?string $term, ?string $accountStatus, ?string $countryCode, bool $includeTaxIdentifiers): array
    {
        $query = $this->createQueryBuilder('client')->orderBy('client.createdAt', 'DESC')->addOrderBy('client.id', 'DESC');
        if ($term !== null && $term !== '') {
            $fields = ['client.title', 'client.legalName', 'client.industry', 'client.website'];
            if ($includeTaxIdentifiers) {
                array_push($fields, 'client.registrationNumber', 'client.vatNumber', 'client.billingEmail');
            }
            $conditions = array_map(static fn (string $field): string => sprintf('LOWER(%s) LIKE :term', $field), $fields);
            $query->andWhere(implode(' OR ', $conditions))
                ->setParameter('term', '%'.addcslashes(mb_strtolower($term), '%_\\').'%');
        }
        if ($accountStatus !== null) {
            $query->andWhere('client.accountStatus = :status')->setParameter('status', $accountStatus);
        }
        if ($countryCode !== null) {
            $query->andWhere('client.countryCode = :country')->setParameter('country', $countryCode);
        }

        return $query->getQuery()->getResult();
    }
}
