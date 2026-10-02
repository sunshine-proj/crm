<?php

namespace App\Tests\Unit\Service;

use App\Entity\Invoice;
use App\Service\InvoiceMoney;
use App\Service\InvoicePdfService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class InvoicePdfServiceTest extends TestCase
{
	public function test_renders_a_pdf_from_invoice_snapshot_data(): void
	{
		$invoice = (new Invoice())
			->setInvoiceNumber('INV-2026-000001')
			->setDetails(
				null,
				null,
				null,
				'Acme Ltd',
				'Website renewal',
				null,
				new DateTimeImmutable('2026-10-02'),
				null,
				'EUR',
				[['task_id' => null, 'description' => 'Design and setup', 'amount' => '125.50']],
				12550,
			);

		$pdf = (new InvoicePdfService(new InvoiceMoney()))->render($invoice);

		self::assertStringStartsWith('%PDF-', $pdf);
		self::assertGreaterThan(1000, strlen($pdf));
	}
}