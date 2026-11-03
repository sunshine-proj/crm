<?php

namespace App\Service;

use App\Entity\Invoice;
use App\Repository\CreditNoteRepository;
use App\Repository\PaymentRepository;

/**
 * The single place that derives what is still owed on an invoice: gross total less credit notes less customer
 * receipts. Status follows from it: issued, partially_paid, paid (settled with at least one receipt) or credited
 * (settled entirely by credit notes).
 */
final class InvoiceSettlement
{
    public function __construct(
        private readonly PaymentRepository $paymentRepository,
        private readonly CreditNoteRepository $creditNoteRepository,
    ) {
    }

    public function paidMinor(Invoice $invoice): int
    {
        return $this->paymentRepository->sumMinorForInvoice((int) $invoice->getId());
    }

    public function creditedMinor(Invoice $invoice): int
    {
        return $this->creditNoteRepository->sumTotalForInvoice((int) $invoice->getId());
    }

    public function balanceDueMinor(Invoice $invoice): int
    {
        return max(0, $invoice->getTotalMinor() - $this->creditedMinor($invoice) - $this->paidMinor($invoice));
    }

    /** Brings the status of an issued invoice in line with its receipts and credit notes. */
    public function refreshStatus(Invoice $invoice): void
    {
        if (!in_array($invoice->getStatus(), ['issued', 'partially_paid', 'paid', 'credited'], true)) {
            return;
        }
        $paid = $this->paidMinor($invoice);
        $balance = $this->balanceDueMinor($invoice);
        $invoice->setStatus(match (true) {
            $balance === 0 && $paid > 0 => 'paid',
            $balance === 0 => 'credited',
            $paid > 0 => 'partially_paid',
            default => 'issued',
        });
    }
}
