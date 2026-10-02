<?php

namespace App\Repository;

use App\Entity\Invoice;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

class InvoiceRepository extends ServiceEntityRepository
{
	public function __construct(ManagerRegistry $registry)
	{
		parent::__construct($registry, Invoice::class);
	}

	/** @param array<string, int|string|\DateTimeImmutable|null> $filters
	 *  @return list<Invoice>
	 */
	public function findFiltered(array $filters): array
	{
		$query = $this->createQueryBuilder('invoice')
			->orderBy('invoice.invoiceDate', 'DESC')
			->addOrderBy('invoice.id', 'DESC');

		$conditions = [
			'client_id' => ['IDENTITY(invoice.client) = :clientId', 'clientId'],
			'project_id' => ['IDENTITY(invoice.project) = :projectId', 'projectId'],
			'manager_id' => ['IDENTITY(invoice.manager) = :managerId', 'managerId'],
			'from' => ['invoice.invoiceDate >= :fromDate', 'fromDate'],
			'to' => ['invoice.invoiceDate <= :toDate', 'toDate'],
			'currency' => ['invoice.currency = :currency', 'currency'],
		];

		foreach ($conditions as $filter => [$condition, $parameter]) {
			if (!isset($filters[$filter]) || $filters[$filter] === '') {
				continue;
			}
			$query->andWhere($condition);
			$type = in_array($filter, ['from', 'to'], true) ? Types::DATE_IMMUTABLE : null;
			$query->setParameter($parameter, $filters[$filter], $type);
		}

		if (isset($filters['min_minor'])) {
			$query->andWhere('invoice.totalMinor >= :minimum')->setParameter('minimum', $filters['min_minor']);
		}
		if (isset($filters['max_minor'])) {
			$query->andWhere('invoice.totalMinor <= :maximum')->setParameter('maximum', $filters['max_minor']);
		}

		return $query->getQuery()->getResult();
	}
}