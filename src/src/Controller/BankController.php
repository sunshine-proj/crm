<?php

namespace App\Controller;

use App\Dto\BankAccountInput;
use App\Dto\LineMatchInput;
use App\Dto\RevocationInput;
use App\Dto\StatementImportInput;
use App\Entity\BankAccount;
use App\Entity\BankStatementImport;
use App\Entity\BankStatementLine;
use App\Entity\LedgerAccount;
use App\Entity\PayablePayment;
use App\Entity\Payment;
use App\Entity\User;
use App\Repository\BankAccountRepository;
use App\Repository\BankStatementImportRepository;
use App\Repository\BankStatementLineRepository;
use App\Repository\LedgerAccountRepository;
use App\Service\Bank\BankReconciliationService;
use App\Service\Bank\BankStatementImporter;
use App\Service\InvoiceMoney;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Bank statement import and reconciliation. Statements are imported as files (camt.053 or CSV); the application never
 * connects to a bank, holds bank credentials, or initiates payments.
 */
#[Route('/api/bank', name: 'api_bank_')]
#[IsGranted('ROLE_FINANCE')]
final class BankController extends AbstractController
{
    public function __construct(
        private readonly BankAccountRepository $accountRepository,
        private readonly BankStatementImportRepository $importRepository,
        private readonly BankStatementLineRepository $lineRepository,
        private readonly LedgerAccountRepository $ledgerAccountRepository,
        private readonly BankStatementImporter $importer,
        private readonly BankReconciliationService $reconciliation,
        private readonly EntityManagerInterface $entityManager,
        private readonly InvoiceMoney $money,
        #[Autowire(service: 'monolog.logger.audit')] private readonly LoggerInterface $auditLogger,
    ) {
    }

    #[Route('/accounts', name: 'accounts', methods: ['GET'])]
    public function accounts(): JsonResponse
    {
        return $this->json(array_map($this->serializeAccount(...), $this->accountRepository->findBy([], ['name' => 'ASC'])));
    }

    #[Route('/accounts', name: 'account_create', methods: ['POST'])]
    public function createAccount(#[MapRequestPayload] BankAccountInput $input): JsonResponse
    {
        if ($input->name === null || $input->currency === null) {
            return $this->json(['error' => 'Name and currency are required.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        $ledger = null;
        if ($input->ledger_account_id !== null) {
            $ledger = $this->ledgerAccountRepository->find($input->ledger_account_id);
            if (!$ledger instanceof LedgerAccount || $ledger->getType() !== 'asset') {
                return $this->json(['error' => 'Choose an asset account from the chart of accounts.'], JsonResponse::HTTP_BAD_REQUEST);
            }
        }
        try {
            $account = new BankAccount($input->name, $input->iban, $input->currency, $ledger);
            $this->entityManager->persist($account);
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            return $this->json(['error' => 'A bank account with this name already exists.'], JsonResponse::HTTP_CONFLICT);
        }

        return $this->json($this->serializeAccount($account), JsonResponse::HTTP_CREATED);
    }

    #[Route('/accounts/{id<\d+>}/imports', name: 'imports', methods: ['GET'])]
    public function imports(int $id): JsonResponse
    {
        $account = $this->accountRepository->find($id);
        if (!$account instanceof BankAccount) {
            return $this->json(['error' => 'Bank account not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        return $this->json(array_map($this->serializeImport(...), $this->importRepository->findBy(['bankAccount' => $account], ['id' => 'DESC'], 50)));
    }

    #[Route('/accounts/{id<\d+>}/imports', name: 'import', methods: ['POST'])]
    public function import(int $id, #[MapRequestPayload] StatementImportInput $input): JsonResponse
    {
        $account = $this->accountRepository->find($id);
        if (!$account instanceof BankAccount) {
            return $this->json(['error' => 'Bank account not found.'], JsonResponse::HTTP_NOT_FOUND);
        }
        try {
            $result = $this->importer->import($account, (string) $input->filename, (string) $input->format, (string) $input->content, $this->actor());
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['error' => $exception->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
        } catch (\DomainException $exception) {
            return $this->json(['error' => $exception->getMessage()], JsonResponse::HTTP_CONFLICT);
        }
        $this->auditLogger->info('Bank statement imported.', ['bank_account_id' => $id, 'import_id' => $result['import']->getId(), 'imported' => $result['imported'], 'skipped' => $result['skipped'], 'actor' => $this->actor()]);

        return $this->json($this->serializeImport($result['import']), JsonResponse::HTTP_CREATED);
    }

    #[Route('/accounts/{id<\d+>}/lines', name: 'lines', methods: ['GET'])]
    public function lines(int $id, Request $request): JsonResponse
    {
        $account = $this->accountRepository->find($id);
        if (!$account instanceof BankAccount) {
            return $this->json(['error' => 'Bank account not found.'], JsonResponse::HTTP_NOT_FOUND);
        }
        $state = $request->query->get('state');
        if ($state !== null && !in_array($state, ['unmatched', 'matched', 'ignored'], true)) {
            return $this->json(['error' => 'State must be unmatched, matched or ignored.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        $limit = $request->query->get('limit', '200');
        if (!ctype_digit((string) $limit) || (int) $limit < 1 || (int) $limit > 1000) {
            return $this->json(['error' => 'limit must be between 1 and 1000.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        return $this->json(array_map($this->serializeLine(...), $this->lineRepository->search($account, $state !== null ? (string) $state : null, null, null, (int) $limit)));
    }

    #[Route('/lines/{id<\d+>}/suggestions', name: 'suggestions', methods: ['GET'])]
    public function suggestions(int $id): JsonResponse
    {
        $line = $this->lineRepository->find($id);
        if (!$line instanceof BankStatementLine) {
            return $this->json(['error' => 'Statement line not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        return $this->json(array_map(fn (array $s): array => ['type' => $s['type'], 'id' => $s['id'], 'score' => $s['score'], 'reasons' => $s['reasons'], 'description' => $s['description'], 'date' => $s['date'], 'amount' => $this->money->formatMinor(abs($s['amount_minor']))], $this->reconciliation->suggestions($line)));
    }

    #[Route('/lines/{id<\d+>}/match', name: 'match', methods: ['POST'])]
    public function match(int $id, #[MapRequestPayload] LineMatchInput $input): JsonResponse
    {
        $line = $this->lineRepository->find($id);
        if (!$line instanceof BankStatementLine) {
            return $this->json(['error' => 'Statement line not found.'], JsonResponse::HTTP_NOT_FOUND);
        }
        if ($input->type === null || $input->id === null) {
            return $this->json(['error' => 'The payment type and id are required.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        try {
            $this->reconciliation->confirm($line, $input->type, $input->id, $this->actor());
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['error' => $exception->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
        } catch (\DomainException $exception) {
            return $this->json(['error' => $exception->getMessage()], JsonResponse::HTTP_CONFLICT);
        }

        return $this->json($this->serializeLine($line));
    }

    #[Route('/lines/{id<\d+>}/unmatch', name: 'unmatch', methods: ['POST'])]
    public function unmatch(int $id): JsonResponse
    {
        $line = $this->lineRepository->find($id);
        if (!$line instanceof BankStatementLine) {
            return $this->json(['error' => 'Statement line not found.'], JsonResponse::HTTP_NOT_FOUND);
        }
        $this->auditLogger->info('Bank statement line unmatched.', ['line_id' => $id, 'previous' => $line->getMatchType(), 'actor' => $this->actor()]);
        $line->unmatch();
        $this->entityManager->flush();

        return $this->json($this->serializeLine($line));
    }

    #[Route('/lines/{id<\d+>}/ignore', name: 'ignore', methods: ['POST'])]
    public function ignore(int $id, #[MapRequestPayload] RevocationInput $input): JsonResponse
    {
        $line = $this->lineRepository->find($id);
        if (!$line instanceof BankStatementLine) {
            return $this->json(['error' => 'Statement line not found.'], JsonResponse::HTTP_NOT_FOUND);
        }
        try {
            $line->ignore((string) $input->reason, $this->actor());
        } catch (\DomainException $exception) {
            return $this->json(['error' => $exception->getMessage()], JsonResponse::HTTP_CONFLICT);
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['error' => $exception->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
        }
        $this->entityManager->flush();

        return $this->json($this->serializeLine($line));
    }

    #[Route('/accounts/{id<\d+>}/auto-match', name: 'auto_match', methods: ['POST'])]
    public function autoMatch(int $id): JsonResponse
    {
        $account = $this->accountRepository->find($id);
        if (!$account instanceof BankAccount) {
            return $this->json(['error' => 'Bank account not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        return $this->json($this->reconciliation->autoMatch($account, $this->actor()));
    }

    #[Route('/accounts/{id<\d+>}/reconciliation', name: 'reconciliation', methods: ['GET'])]
    public function reconciliation(int $id, Request $request): JsonResponse
    {
        $account = $this->accountRepository->find($id);
        if (!$account instanceof BankAccount) {
            return $this->json(['error' => 'Bank account not found.'], JsonResponse::HTTP_NOT_FOUND);
        }
        $from = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request->query->get('from', ''));
        $to = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request->query->get('to', ''));
        if ($from === false || $to === false || $from > $to) {
            return $this->json(['error' => 'Choose a period with from and to dates (YYYY-MM-DD), start not after end.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        $report = $this->reconciliation->report($account, $from, $to);

        return $this->json([
            'bank_account' => $this->serializeAccount($account),
            'period' => ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')],
            'statement_lines' => $report['statement_lines'],
            'statement_net' => $this->money->formatMinor(abs($report['statement_net_minor'])),
            'statement_net_negative' => $report['statement_net_minor'] < 0,
            'unmatched_lines' => array_map($this->serializeLine(...), $report['unmatched_lines']),
            'recorded_receipts_without_bank_line' => array_map(fn (Payment $p): array => ['id' => $p->getId(), 'date' => $p->getPaidAt()->format('Y-m-d'), 'amount' => $this->money->formatMinor($p->getAmountMinor()), 'invoice_number' => $p->getInvoice()->getInvoiceNumber(), 'reference' => $p->getReference()], $report['unreconciled_receipts']),
            'recorded_vendor_payments_without_bank_line' => array_map(fn (PayablePayment $p): array => ['id' => $p->getId(), 'date' => $p->getPaidAt()->format('Y-m-d'), 'amount' => $this->money->formatMinor($p->getAmountMinor()), 'vendor' => $p->getBill()->getVendorName(), 'bill_reference' => $p->getBill()->getVendorReference()], $report['unreconciled_vendor_payments']),
            'reconciled' => $report['statement_lines']['unmatched'] === 0 && $report['unreconciled_receipts'] === [] && $report['unreconciled_vendor_payments'] === [],
            'basis' => 'Compares imported statement lines with the receipts and vendor payments recorded for the period. It does not compare the ledger cash balance with the bank balance and does not post anything.',
        ]);
    }

    private function actor(): string
    {
        $user = $this->getUser();

        return $user instanceof User ? $user->getUserIdentifier() : 'anonymous';
    }

    /** @return array<string, mixed> */
    private function serializeAccount(BankAccount $account): array
    {
        return ['id' => $account->getId(), 'name' => $account->getName(), 'iban' => $account->getIban(), 'currency' => $account->getCurrency(), 'ledger_account_id' => $account->getLedgerAccount()?->getId(), 'active' => $account->isActive()];
    }

    /** @return array<string, mixed> */
    private function serializeImport(BankStatementImport $import): array
    {
        $format = fn (?int $minor): ?string => $minor === null ? null : ($minor < 0 ? '-' : '').$this->money->formatMinor(abs($minor));

        return [
            'id' => $import->getId(), 'bank_account_id' => $import->getBankAccount()->getId(), 'filename' => $import->getFilename(), 'format' => $import->getFormat(),
            'opening_balance' => $format($import->getOpeningBalanceMinor()), 'closing_balance' => $format($import->getClosingBalanceMinor()),
            'period_from' => $import->getPeriodFrom()?->format('Y-m-d'), 'period_to' => $import->getPeriodTo()?->format('Y-m-d'),
            'imported_lines' => $import->getLineCount(), 'skipped_duplicates' => $import->getSkippedCount(),
            'imported_by' => $import->getImportedBy(), 'imported_at' => $import->getImportedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeLine(BankStatementLine $line): array
    {
        return [
            'id' => $line->getId(), 'bank_account_id' => $line->getBankAccount()->getId(), 'booking_date' => $line->getBookingDate()->format('Y-m-d'),
            'amount' => ($line->getAmountMinor() < 0 ? '-' : '').$this->money->formatMinor(abs($line->getAmountMinor())), 'currency' => $line->getCurrency(),
            'reference' => $line->getReference(), 'counterparty' => $line->getCounterparty(), 'external_id' => $line->getExternalId(),
            'state' => $line->getMatchType() === null ? 'unmatched' : ($line->getMatchType() === BankStatementLine::IGNORED ? 'ignored' : 'matched'),
            'match_type' => $line->getMatchType(), 'match_id' => $line->getMatchId(), 'note' => $line->getNote(),
            'matched_by' => $line->getMatchedBy(), 'matched_at' => $line->getMatchedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
