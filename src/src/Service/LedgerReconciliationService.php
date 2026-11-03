<?php

namespace App\Service;

use App\Entity\JournalEntry;
use App\Repository\CreditNoteRepository;
use App\Repository\ExpenseReimbursementRepository;
use App\Repository\ExpenseRepository;
use App\Entity\InventoryValuation;
use App\Repository\InventoryValuationRepository;
use App\Repository\InvoiceRepository;
use App\Repository\JournalEntryRepository;
use App\Repository\PayableBillRepository;
use App\Repository\PayablePaymentRepository;
use App\Repository\PaymentRepository;

/**
 * Compares operational sub-ledgers (invoices, receipts, vendor bills, payments, expenses) with the posted general
 * ledger for one currency: per-source amounts, orphaned postings, and the AR/AP control-account balances.
 * All amounts are integer minor units.
 */
final class LedgerReconciliationService
{
    /** @var array<string, string> posting source type => human record type */
    private const SOURCES = [
        'invoice_issue' => 'invoice',
        'customer_receipt' => 'customer_receipt',
        'credit_note' => 'credit_note',
        'vendor_bill' => 'vendor_bill',
        'vendor_payment' => 'vendor_payment',
        'expense_approval' => 'approved_expense',
        'expense_reimbursement' => 'expense_reimbursement',
        'inventory_receipt' => 'inventory_receipt',
        'inventory_dispatch' => 'inventory_dispatch',
        'inventory_customer_return' => 'inventory_customer_return',
        'inventory_supplier_return' => 'inventory_supplier_return',
        'inventory_adjustment' => 'inventory_adjustment',
        'inventory_opening' => 'inventory_opening',
    ];

    /** @var array<string, string> valuation kind => posting source type */
    private const VALUATION_SOURCES = [
        InventoryValuation::RECEIPT => 'inventory_receipt',
        InventoryValuation::DISPATCH => 'inventory_dispatch',
        InventoryValuation::CUSTOMER_RETURN => 'inventory_customer_return',
        InventoryValuation::SUPPLIER_RETURN => 'inventory_supplier_return',
        InventoryValuation::ADJUSTMENT => 'inventory_adjustment',
        InventoryValuation::OPENING => 'inventory_opening',
    ];

    public function __construct(
        private readonly InvoiceRepository $invoiceRepository,
        private readonly PaymentRepository $paymentRepository,
        private readonly PayableBillRepository $billRepository,
        private readonly PayablePaymentRepository $billPaymentRepository,
        private readonly ExpenseRepository $expenseRepository,
        private readonly ExpenseReimbursementRepository $reimbursementRepository,
        private readonly JournalEntryRepository $journalRepository,
        private readonly FinancePostingService $postingService,
        private readonly InventoryValuationRepository $valuationRepository,
        private readonly CreditNoteRepository $creditNoteRepository,
    ) {
    }

    /**
     * @return array{
     *   currency: string,
     *   missing: list<array{source_type: string, source_id: int, reference: string, expected_minor: int}>,
     *   amount_mismatches: list<array{source_type: string, source_id: int, reference: string, expected_minor: int, posted_minor: int}>,
     *   orphaned: list<array{source_type: string, source_id: int, entry_number: string|null, posted_minor: int}>,
     *   receivables: array{subledger_minor: int, ledger_minor: int|null, difference_minor: int|null},
     *   payables: array{subledger_minor: int, ledger_minor: int|null, difference_minor: int|null},
     *   inventory: array{subledger_minor: int, ledger_minor: int|null, difference_minor: int|null},
     *   goods_received_not_invoiced: array{subledger_minor: int, ledger_minor: int|null, difference_minor: int|null},
     *   tax_payable: array{subledger_minor: int, ledger_minor: int|null, difference_minor: int|null},
     *   reconciled: bool
     * }
     */
    public function reconcile(string $currency): array
    {
        $sources = $this->collectSources($currency);
        $postings = [];
        $entries = $this->journalRepository->findPosted(null, null, $currency);
        foreach ($entries as $entry) {
            if (isset(self::SOURCES[$entry->getSourceType()]) && $entry->getSourceId() !== null) {
                $postings[$entry->getSourceType().':'.$entry->getSourceId()] = $entry;
            }
        }

        $missing = [];
        $mismatches = [];
        foreach ($sources as $key => $source) {
            $entry = $postings[$key] ?? null;
            if ($entry === null) {
                $missing[] = ['source_type' => $source['record_type'], 'source_id' => $source['id'], 'reference' => $source['reference'], 'expected_minor' => $source['amount']];
                continue;
            }
            $posted = $this->debitTotal($entry);
            if ($posted !== $source['amount']) {
                $mismatches[] = ['source_type' => $source['record_type'], 'source_id' => $source['id'], 'reference' => $source['reference'], 'expected_minor' => $source['amount'], 'posted_minor' => $posted];
            }
        }
        $orphaned = [];
        foreach ($postings as $key => $entry) {
            if (!isset($sources[$key])) {
                $orphaned[] = ['source_type' => $entry->getSourceType(), 'source_id' => (int) $entry->getSourceId(), 'entry_number' => $entry->getEntryNumber(), 'posted_minor' => $this->debitTotal($entry)];
            }
        }

        $mappings = $this->postingService->accountMappings();
        $arSub = $this->subledgerTotal($sources, ['invoice_issue'], ['customer_receipt', 'credit_note']);
        $apSub = $this->subledgerTotal($sources, ['vendor_bill'], ['vendor_payment']);
        $arLedger = $this->controlBalance($entries, $mappings['accounts_receivable'] ?? null, true);
        $apLedger = $this->controlBalance($entries, $mappings['accounts_payable'] ?? null, false);

        $receivables = ['subledger_minor' => $arSub, 'ledger_minor' => $arLedger, 'difference_minor' => $arLedger === null ? null : $arLedger - $arSub];
        $payables = ['subledger_minor' => $apSub, 'ledger_minor' => $apLedger, 'difference_minor' => $apLedger === null ? null : $apLedger - $apSub];

        [$inventorySub, $grniSub] = $this->inventorySubledgers($currency);
        $invLedger = $this->controlBalance($entries, $mappings['inventory'] ?? null, true);
        $grniLedger = $this->controlBalance($entries, $mappings['goods_received_not_invoiced'] ?? null, false);
        $inventory = ['subledger_minor' => $inventorySub, 'ledger_minor' => $invLedger, 'difference_minor' => $invLedger === null ? null : $invLedger - $inventorySub];
        $grni = ['subledger_minor' => $grniSub, 'ledger_minor' => $grniLedger, 'difference_minor' => $grniLedger === null ? null : $grniLedger - $grniSub];
        $taxSub = $this->outputTaxTotal($currency);
        $taxLedger = $this->controlBalance($entries, $mappings['tax_payable'] ?? null, false);
        $taxPayable = ['subledger_minor' => $taxSub, 'ledger_minor' => $taxLedger, 'difference_minor' => $taxLedger === null ? null : $taxLedger - $taxSub];

        return [
            'currency' => $currency,
            'missing' => $missing,
            'amount_mismatches' => $mismatches,
            'orphaned' => $orphaned,
            'receivables' => $receivables,
            'payables' => $payables,
            'inventory' => $inventory,
            'goods_received_not_invoiced' => $grni,
            'tax_payable' => $taxPayable,
            'reconciled' => $missing === [] && $mismatches === [] && $orphaned === []
                && $this->controlIsReconciled($receivables) && $this->controlIsReconciled($payables)
                && $this->controlIsReconciled($inventory) && $this->controlIsReconciled($grni) && $this->controlIsReconciled($taxPayable),
        ];
    }

    /** Output tax on issued invoices less tax reversed by credit notes (VAT settlements are not tracked here). */
    private function outputTaxTotal(string $currency): int
    {
        $tax = 0;
        foreach ($this->invoiceRepository->findBy(['currency' => $currency]) as $invoice) {
            if (in_array($invoice->getStatus(), ['issued', 'partially_paid', 'paid', 'credited'], true)) {
                $tax += $invoice->getTaxMinor();
            }
        }
        foreach ($this->creditNoteRepository->findAll() as $note) {
            if ($note->getCurrency() === $currency) {
                $tax -= $note->getTaxMinor();
            }
        }

        return $tax;
    }

    /**
     * A control with a ledger balance must match its sub-ledger. An unmapped control is fine only while its
     * sub-ledger is empty (nothing could have been posted to it).
     *
     * @param array{subledger_minor: int, ledger_minor: int|null, difference_minor: int|null} $control
     */
    private function controlIsReconciled(array $control): bool
    {
        return ($control['difference_minor'] ?? ($control['subledger_minor'] === 0 ? 0 : null)) === 0;
    }

    /** @return array<string, array{id: int, record_type: string, reference: string, amount: int, kind: string}> keyed "type:id" */
    private function collectSources(string $currency): array
    {
        $sources = [];
        $add = static function (string $type, int $id, string $reference, int $amount) use (&$sources): void {
            $sources[$type.':'.$id] = ['id' => $id, 'record_type' => self::SOURCES[$type], 'reference' => $reference, 'amount' => $amount, 'kind' => $type];
        };
        foreach ($this->invoiceRepository->findBy(['currency' => $currency]) as $invoice) {
            if (in_array($invoice->getStatus(), ['issued', 'partially_paid', 'paid', 'credited'], true)) {
                $add('invoice_issue', (int) $invoice->getId(), (string) $invoice->getInvoiceNumber(), $invoice->getTotalMinor());
            }
        }
        foreach ($this->paymentRepository->findAll() as $payment) {
            if ($payment->getInvoice()->getCurrency() === $currency) {
                $add('customer_receipt', (int) $payment->getId(), (string) $payment->getId(), $payment->getAmountMinor());
            }
        }
        foreach ($this->creditNoteRepository->findAll() as $note) {
            if ($note->getCurrency() === $currency) {
                $add('credit_note', (int) $note->getId(), (string) $note->getCreditNoteNumber(), $note->getTotalMinor());
            }
        }
        foreach ($this->billRepository->findBy(['currency' => $currency]) as $bill) {
            $add('vendor_bill', (int) $bill->getId(), $bill->getVendorReference(), $bill->getTotalMinor());
        }
        foreach ($this->billPaymentRepository->findAll() as $payment) {
            if ($payment->getBill()->getCurrency() === $currency) {
                $add('vendor_payment', (int) $payment->getId(), (string) $payment->getId(), $payment->getAmountMinor());
            }
        }
        foreach ($this->expenseRepository->findFiltered(null, 'approved') as $expense) {
            if ($expense->getCurrency() === $currency) {
                $add('expense_approval', (int) $expense->getId(), (string) $expense->getId(), $expense->getAmountMinor());
            }
        }
        foreach ($this->reimbursementRepository->findAll() as $reimbursement) {
            if ($reimbursement->getExpense()->getCurrency() === $currency) {
                $add('expense_reimbursement', (int) $reimbursement->getId(), (string) $reimbursement->getId(), $reimbursement->getAmountMinor());
            }
        }

        foreach ($this->valuationRepository->findAll() as $valuation) {
            if ($valuation->getCurrency() === $currency && $valuation->getValueMinor() !== 0) {
                $add(self::VALUATION_SOURCES[$valuation->getKind()], (int) $valuation->getId(), $valuation->getKind().' #'.$valuation->getSourceId(), abs($valuation->getValueMinor()));
            }
        }

        return $sources;
    }

    /**
     * Stock value and the goods-received-not-invoiced accrual implied by valuation entries and matched bills.
     *
     * @return array{0: int, 1: int}
     */
    private function inventorySubledgers(string $currency): array
    {
        $stock = 0;
        $accrual = 0;
        foreach ($this->valuationRepository->findBy(['currency' => $currency]) as $valuation) {
            $stock += $valuation->getValueMinor();
            if (in_array($valuation->getKind(), [InventoryValuation::RECEIPT, InventoryValuation::SUPPLIER_RETURN], true)) {
                $accrual += $valuation->getValueMinor();
            }
        }
        foreach ($this->billRepository->findBy(['currency' => $currency]) as $bill) {
            $accrual -= $bill->getAccrualClearedMinor();
        }

        return [$stock, $accrual];
    }

    /**
     * @param array<string, array{amount: int, kind: string}> $sources
     * @param list<string> $increases
     * @param list<string> $decreases
     */
    private function subledgerTotal(array $sources, array $increases, array $decreases): int
    {
        $total = 0;
        foreach ($sources as $source) {
            if (in_array($source['kind'], $increases, true)) {
                $total += $source['amount'];
            } elseif (in_array($source['kind'], $decreases, true)) {
                $total -= $source['amount'];
            }
        }

        return $total;
    }

    /** @param list<JournalEntry> $entries already loaded with all of their lines */
    private function controlBalance(array $entries, ?int $accountId, bool $debitNormal): ?int
    {
        if ($accountId === null) {
            return null;
        }
        $balance = 0;
        foreach ($entries as $entry) {
            foreach ($entry->getLines() as $line) {
                if ($line->getAccount()->getId() !== $accountId) {
                    continue;
                }
                $balance += $debitNormal ? $line->getDebitMinor() - $line->getCreditMinor() : $line->getCreditMinor() - $line->getDebitMinor();
            }
        }

        return $balance;
    }

    private function debitTotal(JournalEntry $entry): int
    {
        $total = 0;
        foreach ($entry->getLines() as $line) {
            $total += $line->getDebitMinor();
        }

        return $total;
    }
}
