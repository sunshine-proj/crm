<?php

namespace App\Service;

use App\Entity\Invoice;
use Dompdf\Dompdf;
use Dompdf\Options;

final class InvoicePdfService
{
	public function __construct(private readonly InvoiceMoney $money)
	{
	}

	public function render(Invoice $invoice): string
	{
		$options = new Options();
		$options->set('isRemoteEnabled', false);
		$pdf = new Dompdf($options);
		$rows = '';
		foreach ($invoice->getItems() as $item) {
			$rows .= '<tr><td>'.htmlspecialchars($item['description'], ENT_QUOTES, 'UTF-8').'</td><td class="amount">'.htmlspecialchars($item['amount'], ENT_QUOTES, 'UTF-8').' '.htmlspecialchars($invoice->getCurrency(), ENT_QUOTES, 'UTF-8').'</td></tr>';
		}

		$total = $this->money->formatMinor($invoice->getTotalMinor());
		$html = '<!doctype html><html><head><meta charset="utf-8"><style>
			body{font-family:DejaVu Sans,sans-serif;color:#202b29;font-size:12px}h1{color:#176b63;font-size:28px;margin:0 0 8px}.meta{color:#68756f;margin-bottom:28px}.parties{margin:18px 0 28px}table{width:100%;border-collapse:collapse}th,td{padding:10px;border-bottom:1px solid #dce0d8;text-align:left}th{background:#edf1eb;color:#53645e}.amount{text-align:right}.total{text-align:right;font-size:16px;font-weight:bold;margin-top:20px}.draft{color:#b26a3e;font-weight:bold;text-transform:uppercase}
			</style></head><body><p class="draft">Draft invoice</p><h1>Invoice '.htmlspecialchars((string) $invoice->getInvoiceNumber(), ENT_QUOTES, 'UTF-8').'</h1><p class="meta">Date: '.$invoice->getInvoiceDate()->format('Y-m-d').($invoice->getDueDate() ? ' &nbsp; Due: '.$invoice->getDueDate()->format('Y-m-d') : '').'</p><div class="parties"><strong>Bill to</strong><br>'.htmlspecialchars($invoice->getClientName(), ENT_QUOTES, 'UTF-8').'<br><br><strong>Project</strong><br>'.htmlspecialchars($invoice->getProjectName(), ENT_QUOTES, 'UTF-8').'</div><table><thead><tr><th>Description</th><th class="amount">Amount</th></tr></thead><tbody>'.$rows.'</tbody></table><p class="total">Total: '.$total.' '.htmlspecialchars($invoice->getCurrency(), ENT_QUOTES, 'UTF-8').'</p></body></html>';

		$pdf->loadHtml($html, 'UTF-8');
		$pdf->setPaper('A4');
		$pdf->render();

		return $pdf->output();
	}
}