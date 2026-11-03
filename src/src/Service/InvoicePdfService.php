<?php

namespace App\Service;

use App\Entity\CreditNote;
use App\Entity\Invoice;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Renders invoices and credit notes. Output is a document layout, not a statement of legal compliance: the seller and
 * customer blocks come from the details frozen at issue (or the current organisation profile for drafts).
 */
final class InvoicePdfService
{
	public function __construct(private readonly InvoiceMoney $money, private readonly ?JurisdictionGate $gate = null)
	{
	}

	public function render(Invoice $invoice): string
	{
		$taxed = $invoice->getTaxBreakdown() !== [];
		$rows = '';
		foreach ($invoice->getItems() as $item) {
			$rows .= '<tr><td>'.$this->e($item['description']).'</td>'
				.($taxed ? '<td class="amount">'.$this->e(isset($item['tax_rate_bp']) ? $this->money->formatMinor($item['tax_rate_bp']).'%' : '').'</td>' : '')
				.'<td class="amount">'.$this->e($item['amount']).' '.$this->e($invoice->getCurrency()).'</td></tr>';
		}
		$documentState = $invoice->getStatus() === 'draft' ? 'Draft invoice' : ucfirst(str_replace('_', ' ', $invoice->getStatus())).' invoice';
		$customer = $invoice->getCustomerSnapshot() ?? [];
		$body = '<p class="draft">'.$this->e($documentState).'</p><h1>Invoice '.$this->e((string) $invoice->getInvoiceNumber()).'</h1>'
			.'<p class="meta">Date: '.$invoice->getInvoiceDate()->format('Y-m-d').($invoice->getDueDate() ? ' &nbsp; Due: '.$invoice->getDueDate()->format('Y-m-d') : '').'</p>'
			.$this->issuerBlock($invoice->getIssuerSnapshot())
			.'<div class="parties"><strong>Bill to</strong><br>'.$this->e((string) ($customer['name'] ?? $invoice->getClientName())).$this->partyLines($customer)
			.'<br><br><strong>Project / reference</strong><br>'.$this->e($invoice->getProjectName()).'</div>'
			.'<table><thead><tr><th>Description</th>'.($taxed ? '<th class="amount">Tax rate</th>' : '').'<th class="amount">Net amount</th></tr></thead><tbody>'.$rows.'</tbody></table>'
			.$this->totals($invoice->getCurrency(), $invoice->getNetMinor(), $invoice->getTaxMinor(), $invoice->getTotalMinor(), $invoice->getTaxBreakdown())
			.$this->paymentInstructions($invoice->getIssuerSnapshot());

		return $this->pdf($body);
	}

	public function renderCreditNote(CreditNote $note): string
	{
		$invoice = $note->getInvoice();
		$taxed = $note->getTaxBreakdown() !== [] && $note->getTaxMinor() >= 0 && $invoice->getTaxBreakdown() !== [];
		$rows = '';
		foreach ($note->getItems() as $item) {
			$rows .= '<tr><td>'.$this->e($item['description']).'</td>'
				.($taxed ? '<td class="amount">'.$this->e($this->money->formatMinor($item['tax_rate_bp']).'%').'</td>' : '')
				.'<td class="amount">-'.$this->e($item['amount']).' '.$this->e($note->getCurrency()).'</td></tr>';
		}
		$customer = $invoice->getCustomerSnapshot() ?? [];
		$body = '<p class="draft">Credit note</p><h1>Credit note '.$this->e((string) $note->getCreditNoteNumber()).'</h1>'
			.'<p class="meta">Date: '.$note->getIssueDate()->format('Y-m-d').' &nbsp; Corrects invoice '.$this->e((string) $invoice->getInvoiceNumber()).' of '.$invoice->getInvoiceDate()->format('Y-m-d').'</p>'
			.$this->issuerBlock($invoice->getIssuerSnapshot())
			.'<div class="parties"><strong>Customer</strong><br>'.$this->e((string) ($customer['name'] ?? $invoice->getClientName())).$this->partyLines($customer)
			.'<br><br><strong>Reason</strong><br>'.$this->e($note->getReason()).'</div>'
			.'<table><thead><tr><th>Credited line</th>'.($taxed ? '<th class="amount">Tax rate</th>' : '').'<th class="amount">Net amount</th></tr></thead><tbody>'.$rows.'</tbody></table>'
			.$this->totals($note->getCurrency(), $note->getNetMinor(), $note->getTaxMinor(), $note->getTotalMinor(), $note->getTaxBreakdown(), true);

		return $this->pdf($body);
	}

	/** @param array<string, string|null>|null $issuer */
	private function issuerBlock(?array $issuer): string
	{
		if ($issuer === null) {
			$settings = $this->gate?->settings();
			$issuer = $settings === null ? [] : [
				'legal_name' => $settings->getLegalName(), 'registration_number' => $settings->getRegistrationNumber(),
				'vat_number' => $settings->getVatNumber(), 'address' => $settings->getLegalAddress(), 'email' => $settings->getContactEmail(),
			];
		}
		if (trim((string) ($issuer['legal_name'] ?? '')) === '') {
			return '';
		}

		return '<div class="parties"><strong>'.$this->e((string) $issuer['legal_name']).'</strong>'.$this->partyLines($issuer).'</div>';
	}

	/** @param array<string, string|null> $party */
	private function partyLines(array $party): string
	{
		$out = '';
		if (trim((string) ($party['address'] ?? '')) !== '') {
			$out .= '<br>'.nl2br($this->e((string) $party['address']));
		}
		if (trim((string) ($party['registration_number'] ?? '')) !== '') {
			$out .= '<br>Reg. no. '.$this->e((string) $party['registration_number']);
		}
		if (trim((string) ($party['vat_number'] ?? '')) !== '') {
			$out .= '<br>VAT no. '.$this->e((string) $party['vat_number']);
		}

		return $out;
	}

	/** @param list<array{code: string|null, rate_bp: int, net_minor: int, tax_minor: int}> $breakdown */
	private function totals(string $currency, int $net, int $tax, int $total, array $breakdown, bool $credit = false): string
	{
		$sign = $credit ? '-' : '';
		$c = ' '.$this->e($currency);
		$html = '';
		if ($breakdown !== []) {
			$html .= '<table class="tax"><thead><tr><th>Tax</th><th class="amount">Net</th><th class="amount">Tax</th></tr></thead><tbody>';
			foreach ($breakdown as $row) {
				$html .= '<tr><td>'.$this->e(($row['code'] ?? 'Tax').' '.$this->money->formatMinor($row['rate_bp']).'%').'</td><td class="amount">'.$sign.$this->money->formatMinor($row['net_minor']).$c.'</td><td class="amount">'.$sign.$this->money->formatMinor($row['tax_minor']).$c.'</td></tr>';
			}
			$html .= '</tbody></table><p class="sub">Net total: '.$sign.$this->money->formatMinor($net).$c.' &nbsp; Tax total: '.$sign.$this->money->formatMinor($tax).$c.'</p>';
		}

		return $html.'<p class="total">'.($credit ? 'Credited' : 'Total').': '.$sign.$this->money->formatMinor($total).$c.'</p>';
	}

	/** @param array<string, string|null>|null $issuer */
	private function paymentInstructions(?array $issuer): string
	{
		$text = trim((string) ($issuer['payment_instructions'] ?? ''));

		return $text === '' ? '' : '<p class="sub"><strong>Payment details</strong><br>'.nl2br($this->e($text)).'</p>';
	}

	private function pdf(string $body): string
	{
		$options = new Options();
		$options->set('isRemoteEnabled', false);
		$pdf = new Dompdf($options);
		$pdf->loadHtml('<!doctype html><html><head><meta charset="utf-8"><style>
			body{font-family:DejaVu Sans,sans-serif;color:#202b29;font-size:12px}h1{color:#176b63;font-size:28px;margin:0 0 8px}.meta{color:#68756f;margin-bottom:28px}.parties{margin:18px 0 28px}table{width:100%;border-collapse:collapse}th,td{padding:10px;border-bottom:1px solid #dce0d8;text-align:left}th{background:#edf1eb;color:#53645e}.amount{text-align:right}.total{text-align:right;font-size:16px;font-weight:bold;margin-top:20px}.sub{color:#53645e;margin-top:14px}.tax{margin-top:18px}.draft{color:#b26a3e;font-weight:bold;text-transform:uppercase}
			</style></head><body>'.$body.'</body></html>', 'UTF-8');
		$pdf->setPaper('A4');
		$pdf->render();

		return $pdf->output();
	}

	private function e(string $value): string
	{
		return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
	}
}
