<?php

namespace App\Controller;

use App\Dto\FiscalPeriodInput;
use App\Dto\AccountMappingsInput;
use App\Dto\FiscalPeriodControlInput;
use App\Dto\JournalReversalInput;
use App\Dto\JournalEntryInput;
use App\Dto\LedgerAccountInput;
use App\Dto\PayableBillInput;
use App\Dto\PayablePaymentInput;
use App\Dto\TaxCodeInput;
use App\Entity\FiscalPeriod;
use App\Entity\JournalEntry;
use App\Entity\JournalEntryLine;
use App\Entity\LedgerAccount;
use App\Entity\PayableBill;
use App\Entity\PayablePayment;
use App\Entity\TaxCode;
use App\Entity\Expense;
use App\Entity\ExpenseReimbursement;
use App\Repository\FiscalPeriodRepository;
use App\Repository\InvoiceRepository;
use App\Repository\JournalEntryRepository;
use App\Repository\LedgerAccountRepository;
use App\Repository\PayableBillRepository;
use App\Repository\PayablePaymentRepository;
use App\Repository\PaymentRepository;
use App\Repository\TaxCodeRepository;
use App\Repository\ExpenseRepository;
use App\Repository\ExpenseReimbursementRepository;
use App\Service\FinanceCatalog;
use App\Service\FinancePostingService;
use App\Service\InvoiceMoney;
use App\Service\JournalEntryBalance;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/finance', name: 'api_finance_')]
#[IsGranted('ROLE_USER')]
final class FinanceController extends AbstractController
{
    public function __construct(
        private readonly LedgerAccountRepository $accountRepository,
        private readonly FiscalPeriodRepository $periodRepository,
        private readonly JournalEntryRepository $journalRepository,
        private readonly TaxCodeRepository $taxCodeRepository,
        private readonly PayableBillRepository $billRepository,
        private readonly PayablePaymentRepository $billPaymentRepository,
        private readonly InvoiceRepository $invoiceRepository,
        private readonly PaymentRepository $invoicePaymentRepository,
        private readonly ExpenseRepository $expenseRepository,
        private readonly ExpenseReimbursementRepository $expenseReimbursementRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly InvoiceMoney $money,
        private readonly FinanceCatalog $catalog,
        private readonly JournalEntryBalance $balance,
        private readonly FinancePostingService $postingService,
    ) {
    }

    #[Route('/currencies', name: 'currencies', methods: ['GET'])]
    public function currencies(): JsonResponse
    {
        return $this->json($this->catalog->currencies());
    }

    #[Route('/account-mappings', name: 'account_mappings', methods: ['GET'])]
    #[IsGranted('ROLE_FINANCE')]
    public function accountMappings(): JsonResponse
    {
        return $this->json([
            'mappings' => $this->postingService->accountMappings(),
            'accounts' => array_map(
                fn (LedgerAccount $account): array => $this->serializeAccount($account),
                $this->accountRepository->findBy(['active' => true], ['code' => 'ASC']),
            ),
        ]);
    }

    #[Route('/account-mappings', name: 'account_mappings_update', methods: ['PUT'])]
    #[IsGranted('ROLE_ADMIN')]
    public function updateAccountMappings(#[MapRequestPayload] AccountMappingsInput $input): JsonResponse
    {
        try {
            return $this->json(['mappings' => $this->postingService->updateAccountMappings($input->accountIds())]);
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['error' => $exception->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/accounts', name: 'accounts_list', methods: ['GET'])]
    #[IsGranted('ROLE_FINANCE')]
    public function accounts(): JsonResponse
    {
        return $this->json(array_map(fn (LedgerAccount $account): array => $this->serializeAccount($account), $this->accountRepository->findBy([], ['code' => 'ASC'])));
    }

    #[Route('/accounts', name: 'accounts_create', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function createAccount(#[MapRequestPayload] LedgerAccountInput $input): JsonResponse
    {
        $data = $this->accountData($input);
        if (isset($data['error'])) {
            return $this->json(['error' => $data['error']], JsonResponse::HTTP_BAD_REQUEST);
        }
        if ($this->accountRepository->findOneBy(['code' => $data['code']]) !== null) {
            return $this->json(['error' => 'Account code is already in use.'], JsonResponse::HTTP_CONFLICT);
        }
        $account = new LedgerAccount($data['code'], $data['name'], $data['type'], $data['active']);
        $this->entityManager->persist($account);
        $this->entityManager->flush();
        return $this->json($this->serializeAccount($account), JsonResponse::HTTP_CREATED);
    }

    #[Route('/accounts/{id<\d+>}', name: 'accounts_update', methods: ['PUT', 'PATCH'])]
    #[IsGranted('ROLE_ADMIN')]
    public function updateAccount(int $id, #[MapRequestPayload] LedgerAccountInput $input): JsonResponse
    {
        $account = $this->accountRepository->find($id);
        if ($account === null) {
            return $this->json(['error' => 'Ledger account not found.'], JsonResponse::HTTP_NOT_FOUND);
        }
        $data = $this->accountData($input);
        if (isset($data['error'])) {
            return $this->json(['error' => $data['error']], JsonResponse::HTTP_BAD_REQUEST);
        }
        $duplicate = $this->accountRepository->findOneBy(['code' => $data['code']]);
        if ($duplicate !== null && $duplicate->getId() !== $id) {
            return $this->json(['error' => 'Account code is already in use.'], JsonResponse::HTTP_CONFLICT);
        }
        if ($this->accountRepository->hasJournalLines($id)
            && ($account->getCode() !== $data['code'] || $account->getName() !== $data['name'] || $account->getType() !== $data['type'])) {
            return $this->json(['error' => 'Account code, name, and type are immutable after posting; deactivate the account instead.'], JsonResponse::HTTP_CONFLICT);
        }
        $account->update($data['code'], $data['name'], $data['type'], $data['active']);
        $this->entityManager->flush();
        return $this->json($this->serializeAccount($account));
    }

    #[Route('/periods', name: 'periods_list', methods: ['GET'])]
    #[IsGranted('ROLE_FINANCE')]
    public function periods(): JsonResponse
    {
        $periods = $this->periodRepository->findBy([], ['startsOn' => 'ASC']);
        return $this->json(array_map(fn (FiscalPeriod $period): array => $this->serializePeriod($period), $periods));
    }

    #[Route('/periods', name: 'periods_create', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function createPeriod(#[MapRequestPayload] FiscalPeriodInput $input): JsonResponse
    {
        try {
            $startsOn = $input->starts_on !== null ? $this->parseDate($input->starts_on) : null;
            $endsOn = $input->ends_on !== null ? $this->parseDate($input->ends_on) : null;
        } catch (\InvalidArgumentException) {
            return $this->json(['error' => 'Fiscal period dates must use YYYY-MM-DD.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        $name = trim((string) $input->name);
        if ($name === '' || $startsOn === null || $endsOn === null || $endsOn < $startsOn) {
            return $this->json(['error' => 'A name and valid ordered start/end dates are required.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        if ($this->periodRepository->findOneBy(['name' => $name]) !== null || $this->periodRepository->hasOverlap($startsOn, $endsOn)) {
            return $this->json(['error' => 'Fiscal period name must be unique and date ranges cannot overlap.'], JsonResponse::HTTP_CONFLICT);
        }
        $period = new FiscalPeriod($name, $startsOn, $endsOn);
        $this->entityManager->persist($period);
        $this->entityManager->flush();
        return $this->json($this->serializePeriod($period), JsonResponse::HTTP_CREATED);
    }

    #[Route('/periods/{id<\d+>}/close', name: 'periods_close', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function closePeriod(int $id, #[MapRequestPayload] FiscalPeriodControlInput $input): JsonResponse
    {
        $period = $this->periodRepository->find($id);
        if ($period === null) {
            return $this->json(['error' => 'Fiscal period not found.'], JsonResponse::HTTP_NOT_FOUND);
        }
        $reason = trim((string) $input->reason);
        if ($reason === '') {
            return $this->json(['error' => 'A close reason is required.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        try {
            $period->close($this->getUser()?->getUserIdentifier() ?? 'unknown', $reason, new \DateTimeImmutable());
        } catch (\DomainException $exception) {
            return $this->json(['error' => $exception->getMessage()], JsonResponse::HTTP_CONFLICT);
        }
        $this->entityManager->flush();
        return $this->json($this->serializePeriod($period));
    }

    #[Route('/periods/{id<\d+>}/reopen', name: 'periods_reopen', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function reopenPeriod(int $id, #[MapRequestPayload] FiscalPeriodControlInput $input): JsonResponse
    {
        $period = $this->periodRepository->find($id);
        if ($period === null) {
            return $this->json(['error' => 'Fiscal period not found.'], JsonResponse::HTTP_NOT_FOUND);
        }
        $reason = trim((string) $input->reason);
        if ($reason === '') {
            return $this->json(['error' => 'A reopen reason is required.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        try {
            $period->reopen($this->getUser()?->getUserIdentifier() ?? 'unknown', $reason, new \DateTimeImmutable());
        } catch (\DomainException $exception) {
            return $this->json(['error' => $exception->getMessage()], JsonResponse::HTTP_CONFLICT);
        }
        $this->entityManager->flush();
        return $this->json($this->serializePeriod($period));
    }

    #[Route('/journal-entries', name: 'journal_list', methods: ['GET'])]
    #[IsGranted('ROLE_FINANCE')]
    public function journalEntries(Request $request): JsonResponse
    {
        $filters = $this->parseReportFilters($request);
        if (isset($filters['error'])) {
            return $this->json(['error' => $filters['error']], JsonResponse::HTTP_BAD_REQUEST);
        }
        $entries = $this->journalRepository->findPosted($filters['from'], $filters['to'], $filters['currency']);
        return $this->json(array_map(fn (JournalEntry $entry): array => $this->serializeJournalEntry($entry), $entries));
    }

    #[Route('/journal-entries', name: 'journal_create', methods: ['POST'])]
    #[IsGranted('ROLE_FINANCE')]
    public function createJournalEntry(#[MapRequestPayload] JournalEntryInput $input): JsonResponse
    {
        return $this->saveJournalEntry($input);
    }

    #[Route('/opening-balances', name: 'opening_balance', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function createOpeningBalance(#[MapRequestPayload] JournalEntryInput $input): JsonResponse
    {
        return $this->saveJournalEntry($input, 'opening_balance');
    }

    #[Route('/journal-entries/{id<\d+>}/reverse', name: 'journal_reverse', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function reverseJournalEntry(int $id, #[MapRequestPayload] JournalReversalInput $input): JsonResponse
    {
        $original = $this->journalRepository->find($id);
        if (!$original instanceof JournalEntry) {
            return $this->json(['error' => 'Journal entry not found.'], JsonResponse::HTTP_NOT_FOUND);
        }
        if ($this->journalRepository->findOneBy(['sourceType' => 'reversal', 'sourceId' => $id]) !== null) {
            return $this->json(['error' => 'This journal entry has already been reversed.'], JsonResponse::HTTP_CONFLICT);
        }
        try {
            $date = $input->entry_date !== null ? $this->parseDate($input->entry_date) : null;
        } catch (\InvalidArgumentException) {
            return $this->json(['error' => 'Reversal date must use YYYY-MM-DD.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        $reason = trim((string) $input->reason);
        if ($date === null || $reason === '') {
            return $this->json(['error' => 'A reversal date and reason are required.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        $period = $this->periodRepository->findContaining($date);
        if ($period === null || $period->isClosed()) {
            return $this->json(['error' => 'Reversal date must belong to an open fiscal period.'], JsonResponse::HTTP_CONFLICT);
        }
        $reversal = new JournalEntry($period, $date, $original->getCurrency(), 'Reversal: '.$reason, 'reversal', $id);
        foreach ($original->getLines() as $line) {
            $reversal->addLine(new JournalEntryLine(
                $reversal,
                $line->getAccount(),
                $line->getCreditMinor(),
                $line->getDebitMinor(),
                'Reversal of '.$original->getEntryNumber().': '.$reason,
            ));
        }
        $this->entityManager->persist($reversal);
        $this->entityManager->flush();
        $reversal->setEntryNumber(sprintf('JE-%s-%06d', $date->format('Y'), $reversal->getId()));
        $this->entityManager->flush();
        return $this->json($this->serializeJournalEntry($reversal), JsonResponse::HTTP_CREATED);
    }

    private function saveJournalEntry(JournalEntryInput $input, string $sourceType = 'manual'): JsonResponse
    {
        try {
            $entryDate = $input->entry_date !== null ? $this->parseDate($input->entry_date) : null;
        } catch (\InvalidArgumentException) {
            return $this->json(['error' => 'Entry date must use YYYY-MM-DD.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        $description = trim((string) $input->description);
        if ($entryDate === null || $description === '' || $input->currency === null || !$this->catalog->supportsCurrency($input->currency)) {
            return $this->json(['error' => 'Entry date, description, and supported currency are required.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        $period = $this->periodRepository->findContaining($entryDate);
        if ($period === null || $period->isClosed()) {
            return $this->json(['error' => 'Entry date must belong to an open fiscal period.'], JsonResponse::HTTP_CONFLICT);
        }
        if (count($input->lines) < 2) {
            return $this->json(['error' => 'At least two journal lines are required.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $lines = [];
        $balanceLines = [];
        foreach ($input->lines as $line) {
            $accountId = $line['account_id'] ?? null;
            $debit = $line['debit_minor'] ?? null;
            $credit = $line['credit_minor'] ?? null;
            $memo = trim(is_string($line['memo'] ?? null) ? $line['memo'] : $description);
            if (!is_int($accountId) || !is_int($debit) || !is_int($credit) || $memo === '' || mb_strlen($memo) > 255) {
                return $this->json(['error' => 'Each line requires an account_id, non-negative integer debit_minor/credit_minor, and memo.'], JsonResponse::HTTP_BAD_REQUEST);
            }
            $account = $this->accountRepository->find($accountId);
            if ($account === null || !$account->isActive()) {
                return $this->json(['error' => 'Every line must reference an active ledger account.'], JsonResponse::HTTP_BAD_REQUEST);
            }
            $balanceLines[] = ['debit_minor' => $debit, 'credit_minor' => $credit];
            $lines[] = [$account, $debit, $credit, $memo];
        }
        $totals = $this->balance->totals($balanceLines);
        if ($totals === null) {
            return $this->json(['error' => 'Journal lines must have positive, one-sided amounts and equal debit/credit totals.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $entry = new JournalEntry($period, $entryDate, $input->currency, $description, $sourceType);
        foreach ($lines as [$account, $debit, $credit, $memo]) {
            $entry->addLine(new JournalEntryLine($entry, $account, $debit, $credit, $memo));
        }
        $this->entityManager->persist($entry);
        $this->entityManager->flush();
        $entry->setEntryNumber(sprintf('JE-%s-%06d', $entryDate->format('Y'), $entry->getId()));
        $this->entityManager->flush();
        return $this->json($this->serializeJournalEntry($entry), JsonResponse::HTTP_CREATED);
    }

    #[Route('/tax-codes', name: 'tax_list', methods: ['GET'])]
    #[IsGranted('ROLE_FINANCE')]
    public function taxCodes(): JsonResponse
    {
        return $this->json(array_map(fn (TaxCode $taxCode): array => $this->serializeTaxCode($taxCode), $this->taxCodeRepository->findBy([], ['code' => 'ASC'])));
    }

    #[Route('/tax-codes', name: 'tax_create', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function createTaxCode(#[MapRequestPayload] TaxCodeInput $input): JsonResponse
    {
        $data = $this->taxCodeData($input);
        if (isset($data['error'])) {
            return $this->json(['error' => $data['error']], JsonResponse::HTTP_BAD_REQUEST);
        }
        if ($this->taxCodeRepository->findOneBy(['code' => $data['code']]) !== null) {
            return $this->json(['error' => 'Tax code is already in use.'], JsonResponse::HTTP_CONFLICT);
        }
        $taxCode = new TaxCode($data['code'], $data['name'], $data['rate_basis_points'], $data['active']);
        $this->entityManager->persist($taxCode);
        $this->entityManager->flush();
        return $this->json($this->serializeTaxCode($taxCode), JsonResponse::HTTP_CREATED);
    }

    #[Route('/payables', name: 'payables_list', methods: ['GET'])]
    #[IsGranted('ROLE_FINANCE')]
    public function payables(): JsonResponse
    {
        return $this->json(array_map(fn (PayableBill $bill): array => $this->serializePayable($bill), $this->billRepository->findRecent()));
    }

    #[Route('/payables', name: 'payables_create', methods: ['POST'])]
    #[IsGranted('ROLE_FINANCE')]
    public function createPayable(#[MapRequestPayload] PayableBillInput $input): JsonResponse
    {
        $vendor = trim((string) $input->vendor_name);
        $reference = trim((string) $input->vendor_reference);
        $amount = $input->total !== null ? $this->money->parseMinor($input->total) : null;
        try {
            $billDate = $input->bill_date !== null ? $this->parseDate($input->bill_date) : null;
            $dueDate = $input->due_date !== null ? $this->parseDate($input->due_date) : null;
        } catch (\InvalidArgumentException) {
            return $this->json(['error' => 'Bill and due dates must use YYYY-MM-DD.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        if ($vendor === '' || $reference === '' || mb_strlen($vendor) > 255 || mb_strlen($reference) > 80 || $input->currency === null || !$this->catalog->supportsCurrency($input->currency) || $amount === null || $amount < 1 || $billDate === null) {
            return $this->json(['error' => 'Vendor, reference, supported currency, positive total, and bill date are required.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        if ($dueDate !== null && $dueDate < $billDate) {
            return $this->json(['error' => 'Due date must be on or after the bill date.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        if ($this->billRepository->findOneBy(['vendorName' => $vendor, 'vendorReference' => $reference]) !== null) {
            return $this->json(['error' => 'This vendor bill reference already exists.'], JsonResponse::HTTP_CONFLICT);
        }
        try {
            $this->postingService->assertCanPost($billDate, ['project_expense', 'accounts_payable']);
        } catch (\DomainException $exception) {
            return $this->json(['error' => $exception->getMessage()], JsonResponse::HTTP_CONFLICT);
        }
        $bill = new PayableBill($vendor, $reference, $input->currency, $amount, $billDate, $dueDate);
        $this->postingService->runAtomically(function () use ($bill, $billDate, $amount): void {
            $this->entityManager->persist($bill);
            $this->entityManager->flush();
            $this->postingService->post('vendor_bill', (int) $bill->getId(), $billDate, $bill->getCurrency(), 'Vendor bill '.$bill->getVendorReference(), [
                ['role' => 'project_expense', 'debit_minor' => $amount, 'credit_minor' => 0, 'memo' => 'Vendor bill expense'],
                ['role' => 'accounts_payable', 'debit_minor' => 0, 'credit_minor' => $amount, 'memo' => 'Vendor bill payable'],
            ]);
        });
        return $this->json($this->serializePayable($bill), JsonResponse::HTTP_CREATED);
    }

    #[Route('/payables/{id<\d+>}/payments', name: 'payables_payment', methods: ['POST'])]
    #[IsGranted('ROLE_FINANCE')]
    public function createPayablePayment(int $id, #[MapRequestPayload] PayablePaymentInput $input): JsonResponse
    {
        $bill = $this->billRepository->find($id);
        if ($bill === null) {
            return $this->json(['error' => 'Payable bill not found.'], JsonResponse::HTTP_NOT_FOUND);
        }
        $amount = $input->amount !== null ? $this->money->parseMinor($input->amount) : null;
        try {
            $paidAt = $input->paid_at !== null ? $this->parseDate($input->paid_at) : null;
        } catch (\InvalidArgumentException) {
            return $this->json(['error' => 'Payment date must use YYYY-MM-DD.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        if ($amount === null || $amount < 1 || $paidAt === null) {
            return $this->json(['error' => 'A positive payment amount and payment date are required.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        $paid = $this->billPaymentRepository->sumMinorForBill($id);
        if ($amount > $bill->getTotalMinor() - $paid) {
            return $this->json(['error' => 'Payment exceeds the payable balance.'], JsonResponse::HTTP_CONFLICT);
        }
        try {
            $this->postingService->assertCanPost($paidAt, ['accounts_payable', 'cash']);
        } catch (\DomainException $exception) {
            return $this->json(['error' => $exception->getMessage()], JsonResponse::HTTP_CONFLICT);
        }
        $payment = new PayablePayment($bill, $amount, $paidAt, $input->reference !== null ? trim($input->reference) : null);
        $this->postingService->runAtomically(function () use ($payment, $paidAt, $bill, $amount): void {
            $this->entityManager->persist($payment);
            $this->entityManager->flush();
            $this->postingService->post('vendor_payment', (int) $payment->getId(), $paidAt, $bill->getCurrency(), 'Vendor payment '.$bill->getVendorReference(), [
                ['role' => 'accounts_payable', 'debit_minor' => $amount, 'credit_minor' => 0, 'memo' => 'Vendor payable settlement'],
                ['role' => 'cash', 'debit_minor' => 0, 'credit_minor' => $amount, 'memo' => 'Vendor payment'],
            ]);
        });
        return $this->json([
            'id' => $payment->getId(),
            'bill_id' => $id,
            'amount' => $this->money->formatMinor($amount),
            'currency' => $bill->getCurrency(),
            'paid_at' => $paidAt->format('Y-m-d'),
            'reference' => $payment->getReference(),
            'balance_due' => $this->money->formatMinor($bill->getTotalMinor() - $paid - $amount),
        ], JsonResponse::HTTP_CREATED);
    }

    #[Route('/reports/trial-balance', name: 'trial_balance', methods: ['GET'])]
    #[IsGranted('ROLE_FINANCE')]
    public function trialBalance(Request $request): JsonResponse
    {
        $filters = $this->parseReportFilters($request);
        if (isset($filters['error'])) {
            return $this->json(['error' => $filters['error']], JsonResponse::HTTP_BAD_REQUEST);
        }
        if ($filters['currency'] === null) {
            return $this->json(['error' => 'Choose one currency for the trial balance; cross-currency aggregation is not supported.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        $entries = $this->journalRepository->findPosted($filters['from'], $filters['to'], $filters['currency']);
        $totals = [];
        $debits = 0;
        $credits = 0;
        foreach ($entries as $entry) {
            foreach ($entry->getLines() as $line) {
                $accountId = (int) $line->getAccount()->getId();
                $totals[$accountId] ??= ['account' => $line->getAccount(), 'debit_minor' => 0, 'credit_minor' => 0];
                $totals[$accountId]['debit_minor'] += $line->getDebitMinor();
                $totals[$accountId]['credit_minor'] += $line->getCreditMinor();
                $debits += $line->getDebitMinor();
                $credits += $line->getCreditMinor();
            }
        }
        $accounts = array_values(array_map(fn (array $row): array => [
            'account_id' => $row['account']->getId(),
            'code' => $row['account']->getCode(),
            'name' => $row['account']->getName(),
            'type' => $row['account']->getType(),
            'debit' => $this->money->formatMinor($row['debit_minor']),
            'credit' => $this->money->formatMinor($row['credit_minor']),
            'balance' => $this->money->formatMinor($row['debit_minor'] - $row['credit_minor']),
        ], $totals));
        return $this->json([
            'from' => $filters['from']?->format('Y-m-d'),
            'to' => $filters['to']?->format('Y-m-d'),
            'currency' => $filters['currency'],
            'accounts' => $accounts,
            'total_debits' => $this->money->formatMinor($debits),
            'total_credits' => $this->money->formatMinor($credits),
            'balanced' => $debits === $credits,
            'basis' => 'Posted journal entries only; single-currency totals; no FX translation.',
        ]);
    }

    #[Route('/reports/financial-statements', name: 'financial_statements', methods: ['GET'])]
    #[IsGranted('ROLE_FINANCE')]
    public function financialStatements(Request $request): JsonResponse
    {
        $filters = $this->parseReportFilters($request);
        if (isset($filters['error'])) {
            return $this->json(['error' => $filters['error']], JsonResponse::HTTP_BAD_REQUEST);
        }
        if ($filters['currency'] === null) {
            return $this->json(['error' => 'Choose one currency for financial statements.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $periodEntries = $this->journalRepository->findPosted($filters['from'], $filters['to'], $filters['currency']);
        $throughDateEntries = $this->journalRepository->findPosted(null, $filters['to'], $filters['currency']);
        $income = $this->statementTotals($periodEntries, ['revenue', 'expense']);
        $cumulativeIncome = $this->statementTotals($throughDateEntries, ['revenue', 'expense']);
        $balance = $this->statementTotals($throughDateEntries, ['asset', 'liability', 'equity']);
        $revenue = 0;
        $expenses = 0;
        foreach ($income as $row) {
            if ($row['type'] === 'revenue') {
                $revenue -= $row['balance_minor'];
            } else {
                $expenses += $row['balance_minor'];
            }
        }
        $netIncome = $revenue - $expenses;
        $retainedEarnings = 0;
        foreach ($cumulativeIncome as $row) {
            $retainedEarnings -= $row['balance_minor'];
        }
        $assetTotal = 0;
        $liabilityAndEquity = 0;
        $balanceAccounts = [];
        foreach ($balance as $row) {
            $amount = $row['type'] === 'asset' ? $row['balance_minor'] : -$row['balance_minor'];
            if ($row['type'] === 'asset') {
                $assetTotal += $amount;
            } else {
                $liabilityAndEquity += $amount;
            }
            $balanceAccounts[] = $this->formatStatementAccount($row, $amount);
        }
        $liabilityAndEquity += $retainedEarnings;

        $cashAccountId = $this->postingService->accountMappings()['cash'];
        $cashInflows = 0;
        $cashOutflows = 0;
        if ($cashAccountId !== null) {
            foreach ($periodEntries as $entry) {
                foreach ($entry->getLines() as $line) {
                    if ($line->getAccount()->getId() !== $cashAccountId) {
                        continue;
                    }
                    $net = $line->getDebitMinor() - $line->getCreditMinor();
                    if ($net > 0) {
                        $cashInflows += $net;
                    } elseif ($net < 0) {
                        $cashOutflows += -$net;
                    }
                }
            }
        }

        return $this->json([
            'from' => $filters['from']?->format('Y-m-d'),
            'to' => $filters['to']?->format('Y-m-d'),
            'currency' => $filters['currency'],
            'profit_and_loss' => [
                'revenue' => $this->money->formatMinor($revenue),
                'expenses' => $this->money->formatMinor($expenses),
                'net_income' => $this->money->formatMinor($netIncome),
                'accounts' => array_map(
                    fn (array $row): array => $this->formatStatementAccount(
                        $row,
                        $row['type'] === 'revenue' ? -$row['balance_minor'] : $row['balance_minor'],
                    ),
                    $income,
                ),
            ],
            'balance_sheet' => [
                'assets' => $this->money->formatMinor($assetTotal),
                'liabilities_and_equity' => $this->money->formatMinor($liabilityAndEquity),
                'retained_earnings_and_current_results' => $this->money->formatMinor($retainedEarnings),
                'balanced' => $assetTotal === $liabilityAndEquity,
                'accounts' => $balanceAccounts,
            ],
            'cash_flow' => [
                'cash_inflows' => $this->money->formatMinor($cashInflows),
                'cash_outflows' => $this->money->formatMinor($cashOutflows),
                'net_cash_change' => $this->money->formatMinor($cashInflows - $cashOutflows),
                'basis' => 'Operational cash-account movements from posted journal entries; not a statutory cash-flow statement.',
            ],
            'basis' => 'Operational single-currency summaries from posted journal entries. Balance sheet includes current-period earnings; not statutory financial statements.',
        ]);
    }

    #[Route('/reports/reconciliation', name: 'reconciliation', methods: ['GET'])]
    #[IsGranted('ROLE_FINANCE')]
    public function reconciliation(): JsonResponse
    {
        $missing = [];
        foreach ($this->invoiceRepository->findAll() as $invoice) {
            if (in_array($invoice->getStatus(), ['issued', 'partially_paid', 'paid'], true)) {
                $this->appendMissingPosting($missing, 'invoice_issue', (int) $invoice->getId(), 'invoice', (string) $invoice->getInvoiceNumber(), $invoice->getCurrency(), $invoice->getTotalMinor());
            }
        }
        foreach ($this->invoicePaymentRepository->findAll() as $payment) {
            $this->appendMissingPosting($missing, 'customer_receipt', (int) $payment->getId(), 'customer_receipt', (string) $payment->getId(), $payment->getInvoice()->getCurrency(), $payment->getAmountMinor());
        }
        foreach ($this->billRepository->findAll() as $bill) {
            $this->appendMissingPosting($missing, 'vendor_bill', (int) $bill->getId(), 'vendor_bill', $bill->getVendorReference(), $bill->getCurrency(), $bill->getTotalMinor());
        }
        foreach ($this->billPaymentRepository->findAll() as $payment) {
            $this->appendMissingPosting($missing, 'vendor_payment', (int) $payment->getId(), 'vendor_payment', (string) $payment->getId(), $payment->getBill()->getCurrency(), $payment->getAmountMinor());
        }
        foreach ($this->expenseRepository->findFiltered(null, 'approved') as $expense) {
            $this->appendMissingPosting($missing, 'expense_approval', (int) $expense->getId(), 'approved_expense', (string) $expense->getId(), $expense->getCurrency(), $expense->getAmountMinor());
        }
        foreach ($this->expenseReimbursementRepository->findAll() as $reimbursement) {
            $this->appendMissingPosting($missing, 'expense_reimbursement', (int) $reimbursement->getId(), 'expense_reimbursement', (string) $reimbursement->getId(), $reimbursement->getExpense()->getCurrency(), $reimbursement->getAmountMinor());
        }

        return $this->json([
            'reconciled' => $missing === [],
            'missing_postings' => $missing,
            'missing_count' => count($missing),
            'basis' => 'Operational source-record presence check against idempotent journal source references; does not validate jurisdictional treatment or reconcile external bank statements.',
        ]);
    }

    /** @param list<JournalEntry> $entries
     *  @param list<string> $types
     *  @return list<array{account: LedgerAccount, type: string, balance_minor: int}>
     */
    private function statementTotals(array $entries, array $types): array
    {
        $totals = [];
        foreach ($entries as $entry) {
            foreach ($entry->getLines() as $line) {
                $account = $line->getAccount();
                if (!in_array($account->getType(), $types, true)) {
                    continue;
                }
                $id = (int) $account->getId();
                $totals[$id] ??= ['account' => $account, 'type' => $account->getType(), 'balance_minor' => 0];
                $totals[$id]['balance_minor'] += $line->getDebitMinor() - $line->getCreditMinor();
            }
        }

        return array_values($totals);
    }

    /** @param array{account: LedgerAccount, type: string, balance_minor: int} $row
     *  @return array{account_id: int|null, code: string, name: string, type: string, balance: string}
     */
    private function formatStatementAccount(array $row, ?int $balance = null): array
    {
        return [
            'account_id' => $row['account']->getId(),
            'code' => $row['account']->getCode(),
            'name' => $row['account']->getName(),
            'type' => $row['type'],
            'balance' => $this->money->formatMinor($balance ?? $row['balance_minor']),
        ];
    }

    /** @param list<array<string, int|string>> $missing */
    private function appendMissingPosting(
        array &$missing,
        string $sourceType,
        int $sourceId,
        string $recordType,
        string $reference,
        string $currency,
        int $amountMinor,
    ): void {
        if ($this->journalRepository->findOneBy(['sourceType' => $sourceType, 'sourceId' => $sourceId]) !== null) {
            return;
        }
        $missing[] = [
            'source_type' => $recordType,
            'source_id' => $sourceId,
            'reference' => $reference,
            'currency' => $currency,
            'amount' => $this->money->formatMinor($amountMinor),
        ];
    }

    #[Route('/reports/receivables-payables', name: 'ar_ap', methods: ['GET'])]
    #[IsGranted('ROLE_FINANCE')]
    public function receivablesPayables(Request $request): JsonResponse
    {
        $currency = $request->query->get('currency');
        if ($currency === null || !$this->catalog->supportsCurrency($currency)) {
            return $this->json(['error' => 'Choose one supported currency: EUR, GBP, or USD.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        $today = new \DateTimeImmutable('today');
        $receivables = [];
        $arTotal = 0;
        foreach ($this->invoiceRepository->findBy(['currency' => $currency], ['invoiceDate' => 'ASC']) as $invoice) {
            if (!in_array($invoice->getStatus(), ['issued', 'partially_paid'], true)) {
                continue;
            }
            $paid = $this->invoicePaymentRepository->sumMinorForInvoice((int) $invoice->getId());
            $due = max(0, $invoice->getTotalMinor() - $paid);
            if ($due === 0) {
                continue;
            }
            $arTotal += $due;
            $receivables[] = [
                'invoice_id' => $invoice->getId(),
                'invoice_number' => $invoice->getInvoiceNumber(),
                'client_name' => $invoice->getClientName(),
                'due_date' => $invoice->getDueDate()?->format('Y-m-d'),
                'overdue' => $invoice->getDueDate() !== null && $invoice->getDueDate() < $today,
                'balance_due' => $this->money->formatMinor($due),
            ];
        }
        $payables = [];
        $apTotal = 0;
        foreach ($this->billRepository->findBy(['currency' => $currency], ['billDate' => 'ASC']) as $bill) {
            $paid = $this->billPaymentRepository->sumMinorForBill((int) $bill->getId());
            $due = max(0, $bill->getTotalMinor() - $paid);
            if ($due === 0) {
                continue;
            }
            $apTotal += $due;
            $payables[] = [
                'bill_id' => $bill->getId(),
                'vendor_name' => $bill->getVendorName(),
                'vendor_reference' => $bill->getVendorReference(),
                'due_date' => $bill->getDueDate()?->format('Y-m-d'),
                'overdue' => $bill->getDueDate() !== null && $bill->getDueDate() < $today,
                'balance_due' => $this->money->formatMinor($due),
            ];
        }
        return $this->json([
            'currency' => $currency,
            'receivables_total' => $this->money->formatMinor($arTotal),
            'receivables' => $receivables,
            'payables_total' => $this->money->formatMinor($apTotal),
            'payables' => $payables,
            'basis' => 'Operational invoice and vendor-bill balances; not a general-ledger reconciliation.',
        ]);
    }

    private function accountData(LedgerAccountInput $input): array
    {
        $code = strtoupper(trim((string) $input->code));
        $name = trim((string) $input->name);
        if ($code === '' || $name === '' || $input->type === null || preg_match('/^[A-Z0-9_-]{1,32}$/D', $code) !== 1) {
            return ['error' => 'Account code, name, and supported account type are required.'];
        }
        return ['code' => $code, 'name' => $name, 'type' => $input->type, 'active' => $input->active];
    }

    private function taxCodeData(TaxCodeInput $input): array
    {
        $code = strtoupper(trim((string) $input->code));
        $name = trim((string) $input->name);
        $rate = $input->rate_percent !== null ? $this->catalog->parseRateBasisPoints($input->rate_percent) : null;
        if ($code === '' || $name === '' || preg_match('/^[A-Z0-9_-]{1,32}$/D', $code) !== 1 || $rate === null) {
            return ['error' => 'Tax code, name, and a rate from 0.00% through 100.00% are required.'];
        }
        return ['code' => $code, 'name' => $name, 'rate_basis_points' => $rate, 'active' => $input->active];
    }

    private function parseDate(string $value): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('Invalid calendar date.');
        }
        return $date;
    }

    private function parseReportFilters(Request $request): array
    {
        $filters = ['from' => null, 'to' => null, 'currency' => null];
        foreach (['from', 'to'] as $key) {
            $value = $request->query->get($key);
            if ($value === null || $value === '') {
                continue;
            }
            try {
                $filters[$key] = $this->parseDate((string) $value);
            } catch (\InvalidArgumentException) {
                return ['error' => 'Report dates must use YYYY-MM-DD.'];
            }
        }
        if ($filters['from'] !== null && $filters['to'] !== null && $filters['from'] > $filters['to']) {
            return ['error' => 'Report start date must be on or before the end date.'];
        }
        $currency = $request->query->get('currency');
        if ($currency !== null && $currency !== '') {
            if (!$this->catalog->supportsCurrency($currency)) {
                return ['error' => 'Currency must be EUR, GBP, or USD.'];
            }
            $filters['currency'] = $currency;
        }
        return $filters;
    }

    private function serializeAccount(LedgerAccount $account): array
    {
        return ['id' => $account->getId(), 'code' => $account->getCode(), 'name' => $account->getName(), 'type' => $account->getType(), 'active' => $account->isActive()];
    }

    private function serializePeriod(FiscalPeriod $period): array
    {
        return ['id' => $period->getId(), 'name' => $period->getName(), 'starts_on' => $period->getStartsOn()->format('Y-m-d'), 'ends_on' => $period->getEndsOn()->format('Y-m-d'), 'closed' => $period->isClosed()];
    }

    private function serializeTaxCode(TaxCode $taxCode): array
    {
        return ['id' => $taxCode->getId(), 'code' => $taxCode->getCode(), 'name' => $taxCode->getName(), 'rate_percent' => $this->catalog->formatRateBasisPoints($taxCode->getRateBasisPoints()), 'active' => $taxCode->isActive()];
    }

    private function serializeJournalEntry(JournalEntry $entry): array
    {
        $debits = 0;
        $credits = 0;
        $lines = [];
        foreach ($entry->getLines() as $line) {
            $debits += $line->getDebitMinor();
            $credits += $line->getCreditMinor();
            $lines[] = [
                'account_id' => $line->getAccount()->getId(),
                'account_code' => $line->getAccount()->getCode(),
                'account_name' => $line->getAccount()->getName(),
                'debit' => $this->money->formatMinor($line->getDebitMinor()),
                'credit' => $this->money->formatMinor($line->getCreditMinor()),
                'memo' => $line->getMemo(),
            ];
        }
        return [
            'id' => $entry->getId(),
            'entry_number' => $entry->getEntryNumber(),
            'entry_date' => $entry->getEntryDate()->format('Y-m-d'),
            'period' => $entry->getPeriod()->getName(),
            'currency' => $entry->getCurrency(),
            'description' => $entry->getDescription(),
            'source_type' => $entry->getSourceType(),
            'source_id' => $entry->getSourceId(),
            'lines' => $lines,
            'debit_total' => $this->money->formatMinor($debits),
            'credit_total' => $this->money->formatMinor($credits),
        ];
    }

    private function serializePayable(PayableBill $bill): array
    {
        $paid = $this->billPaymentRepository->sumMinorForBill((int) $bill->getId());
        return [
            'id' => $bill->getId(),
            'vendor_name' => $bill->getVendorName(),
            'vendor_reference' => $bill->getVendorReference(),
            'currency' => $bill->getCurrency(),
            'total' => $this->money->formatMinor($bill->getTotalMinor()),
            'paid_total' => $this->money->formatMinor($paid),
            'balance_due' => $this->money->formatMinor(max(0, $bill->getTotalMinor() - $paid)),
            'bill_date' => $bill->getBillDate()->format('Y-m-d'),
            'due_date' => $bill->getDueDate()?->format('Y-m-d'),
            'status' => $paid === 0 ? 'open' : ($paid >= $bill->getTotalMinor() ? 'paid' : 'partially_paid'),
        ];
    }
}