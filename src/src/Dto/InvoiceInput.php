<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class InvoiceInput
{
	/** @param list<array<string, mixed>> $items */
	public function __construct(
		#[Assert\Positive]
		public readonly ?int $project_id = null,
		#[Assert\Date]
		public readonly ?string $invoice_date = null,
		#[Assert\Date]
		public readonly ?string $due_date = null,
		#[Assert\Choice(choices: ['EUR', 'GBP', 'USD'])]
		public readonly ?string $currency = null,
		#[Assert\Count(min: 1, max: 100)]
		public readonly array $items = [],
	) {
	}
}