<?php

namespace App\Service;

use App\Entity\CreditNote;
use App\Entity\Invoice;
use App\Repository\CreditNoteRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Issues credit notes against issued invoices. A credit note may not exceed what is still owed (refunding money
 * already received is not supported) nor, per tax rate, the net amount originally invoiced. Tax on the credit uses the
 * rates frozen on the invoice, never today's tax codes.
 */
final class CreditNoteService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CreditNoteRepository $creditNoteRepository,
        private readonly InvoiceSettlement $settlement,
        private readonly TaxCalculator $calculator,
        private readonly InvoiceMoney $money,
        private readonly DocumentNumbering $numbering,
        private readonly FinancePostingService $postingService,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $lines description, net amount, optional tax_code; empty means "credit the whole invoice"
     * @throws \DomainException|\InvalidArgumentException
     */
    public function issue(Invoice $invoice, string $reason, array $lines, \DateTimeImmutable $date, string $actor): CreditNote
    {
        $this->numbering->prepare('CN', $date);

        return $this->postingService->runAtomically(function () use ($invoice, $reason, $lines, $date, $actor): CreditNote {
            // Serialise with receipts and other credit notes on this invoice before checking what is still owed.
            $this->entityManager->refresh($invoice, LockMode::PESSIMISTIC_WRITE);

            return $this->create($invoice, $reason, $lines, $date, $actor);
        });
    }

    /** @param list<array<string, mixed>> $lines */
    private function create(Invoice $invoice, string $reason, array $lines, \DateTimeImmutable $date, string $actor): CreditNote
    {
        if (!in_array($invoice->getStatus(), ['issued', 'partially_paid'], true)) {
            throw new \DomainException('Only issued invoices with an outstanding balance can be credited.');
        }
        if ($date < $invoice->getInvoiceDate()) {
            throw new \DomainException('The credit note date cannot be before the invoice date.');
        }
        $breakdown = $invoice->getTaxBreakdown();
        $ratesByCode = [];
        foreach ($breakdown as $row) {
            $ratesByCode[(string) $row['code']] = $row;
        }
        $previous = $this->creditNoteRepository->forInvoice((int) $invoice->getId());
        if ($lines === []) {
            if ($previous !== []) {
                throw new \DomainException('Specify the lines to credit: this invoice already has credit notes.');
            }
            foreach ($invoice->getItems() as $item) {
                $lines[] = ['description' => $item['description'], 'amount' => $item['amount'], 'tax_code' => $item['tax_code'] ?? null];
            }
        }

        $items = [];
        $calcLines = [];
        foreach ($lines as $line) {
            $description = trim((string) ($line['description'] ?? ''));
            $minor = isset($line['amount']) && is_string($line['amount']) ? $this->money->parseMinor($line['amount']) : null;
            if ($description === '' || mb_strlen($description) > 255 || $minor === null || $minor < 1) {
                throw new \InvalidArgumentException('Each credited line needs a description and a positive net amount with at most two decimals.');
            }
            $code = isset($line['tax_code']) && is_string($line['tax_code']) && $line['tax_code'] !== '' ? strtoupper($line['tax_code']) : null;
            if ($breakdown === []) {
                $rate = 0;
                $code = null;
            } else {
                if ($code === null || !isset($ratesByCode[$code])) {
                    throw new \InvalidArgumentException('Each credited line must use one of the tax codes on the invoice ('.implode(', ', array_keys($ratesByCode)).').');
                }
                $rate = $ratesByCode[$code]['rate_bp'];
            }
            $calcLines[] = ['net_minor' => $minor, 'rate_bp' => $rate, 'code' => $code];
            $items[] = ['description' => $description, 'amount' => $this->money->formatMinor($minor), 'tax_code' => $code, 'tax_rate_bp' => $rate];
        }
        $result = $this->calculator->calculate($calcLines, $invoice->getTaxRounding() ?? 'per_rate_group');

        // Never credit more net than was invoiced at a rate, counting earlier credit notes.
        $creditedNet = [];
        foreach ($previous as $note) {
            foreach ($note->getTaxBreakdown() as $row) {
                $creditedNet[(string) $row['code']] = ($creditedNet[(string) $row['code']] ?? 0) + $row['net_minor'];
            }
        }
        $invoicedNet = $breakdown === [] ? ['' => $invoice->getNetMinor()] : array_column(array_map(static fn (array $row): array => [(string) $row['code'], $row['net_minor']], $breakdown), 1, 0);
        foreach ($result['breakdown'] as $row) {
            $key = (string) $row['code'];
            if (($creditedNet[$key] ?? 0) + $row['net_minor'] > ($invoicedNet[$key] ?? 0)) {
                throw new \DomainException('The credit exceeds the net amount invoiced'.($key !== '' ? ' at tax code '.$key : '').'.');
            }
        }
        if ($result['gross_minor'] > $this->settlement->balanceDueMinor($invoice)) {
            throw new \DomainException('The credit exceeds the balance still owed. Refunding amounts already received is not supported.');
        }
        $roles = ['accounts_receivable', 'revenue'];
        if ($result['tax_minor'] > 0) {
            $roles[] = 'tax_payable';
        }
        $this->postingService->assertCanPost($date, $roles);

        $note = new CreditNote($invoice, $date, $reason, $items, $result['net_minor'], $result['tax_minor'], $result['breakdown'], $actor);
        $this->entityManager->persist($note);
        $this->entityManager->flush();
        $note->assignNumber($this->numbering->isSequential() ? $this->numbering->allocate('CN', $date) : sprintf('CN-%s-%06d', $date->format('Y'), $note->getId()));
        $this->entityManager->flush();

        $label = 'Credit note '.$note->getCreditNoteNumber().' for invoice '.$invoice->getInvoiceNumber();
        $lines = [['role' => 'revenue', 'debit_minor' => $note->getNetMinor(), 'credit_minor' => 0, 'memo' => $label]];
        if ($note->getTaxMinor() > 0) {
            $lines[] = ['role' => 'tax_payable', 'debit_minor' => $note->getTaxMinor(), 'credit_minor' => 0, 'memo' => $label];
        }
        $lines[] = ['role' => 'accounts_receivable', 'debit_minor' => 0, 'credit_minor' => $note->getTotalMinor(), 'memo' => $label];
        $this->postingService->post('credit_note', (int) $note->getId(), $date, $invoice->getCurrency(), $label, $lines);
        $this->settlement->refreshStatus($invoice);
        $this->entityManager->flush();

        return $note;
    }
}
