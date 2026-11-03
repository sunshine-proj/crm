<?php

namespace App\Service;

use App\Entity\FiscalPeriod;
use App\Entity\JournalEntry;
use App\Entity\JournalEntryLine;
use App\Entity\LedgerAccount;
use App\Entity\OrganizationSettings;
use App\Repository\FiscalPeriodRepository;
use App\Repository\JournalEntryRepository;
use App\Repository\LedgerAccountRepository;
use App\Repository\OrganizationSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;

final class FinancePostingService
{
    public function __construct(
        private readonly OrganizationSettingsRepository $settingsRepository,
        private readonly FiscalPeriodRepository $periodRepository,
        private readonly LedgerAccountRepository $accountRepository,
        private readonly JournalEntryRepository $journalRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param list<array{role: string, debit_minor: int, credit_minor: int, memo: string}> $lines
     */
    public function post(
        string $sourceType,
        int $sourceId,
        \DateTimeImmutable $date,
        string $currency,
        string $description,
        array $lines,
    ): JournalEntry {
        $existing = $this->journalRepository->findOneBy(['sourceType' => $sourceType, 'sourceId' => $sourceId]);
        if ($existing instanceof JournalEntry) {
            return $existing;
        }

        $period = $this->periodRepository->findContaining($date);
        if (!$period instanceof FiscalPeriod || $period->isClosed()) {
            throw new \DomainException('An open fiscal period is required for automatic posting.');
        }
        $settings = $this->settingsRepository->find(1);
        if (!$settings instanceof OrganizationSettings) {
            throw new \DomainException('Configure finance account mappings before posting transactions.');
        }
        $mappings = $settings->getAccountMappings();
        $resolvedLines = [];
        $totalDebits = 0;
        $totalCredits = 0;
        foreach ($lines as $line) {
            $account = $mappings[$line['role']] ?? null;
            if (!$account instanceof LedgerAccount || !$account->isActive()) {
                throw new \DomainException(sprintf('Configure an active %s account before posting.', str_replace('_', ' ', $line['role'])));
            }
            if ($line['debit_minor'] < 0 || $line['credit_minor'] < 0
                || ($line['debit_minor'] > 0) === ($line['credit_minor'] > 0)
                || $line['debit_minor'] > PHP_INT_MAX - $totalDebits
                || $line['credit_minor'] > PHP_INT_MAX - $totalCredits) {
                throw new \InvalidArgumentException('Automatic journal lines must have positive one-sided amounts within the supported range.');
            }
            $totalDebits += $line['debit_minor'];
            $totalCredits += $line['credit_minor'];
            $resolvedLines[] = [$account, $line['debit_minor'], $line['credit_minor'], $line['memo']];
        }
        if ($totalDebits < 1 || $totalDebits !== $totalCredits) {
            throw new \InvalidArgumentException('Automatic journal debit and credit totals must be positive and equal.');
        }

        $entry = new JournalEntry($period, $date, $currency, $description, $sourceType, $sourceId);
        foreach ($resolvedLines as [$account, $debit, $credit, $memo]) {
            $entry->addLine(new JournalEntryLine($entry, $account, $debit, $credit, $memo));
        }
        $this->entityManager->persist($entry);
        $this->entityManager->flush();
        $entry->setEntryNumber(sprintf('JE-%s-%06d', $date->format('Y'), $entry->getId()));
        $this->entityManager->flush();

        return $entry;
    }

    /** @param list<string> $roles */
    public function assertCanPost(\DateTimeImmutable $date, array $roles): void
    {
        $period = $this->periodRepository->findContaining($date);
        if (!$period instanceof FiscalPeriod || $period->isClosed()) {
            throw new \DomainException('An open fiscal period is required for automatic posting.');
        }
        $settings = $this->settingsRepository->find(1);
        if (!$settings instanceof OrganizationSettings) {
            throw new \DomainException('Configure finance account mappings before posting transactions.');
        }
        foreach (array_unique($roles) as $role) {
            $account = $settings->getAccountMappings()[$role] ?? null;
            if (!$account instanceof LedgerAccount || !$account->isActive()) {
                throw new \DomainException(sprintf('Configure an active %s account before posting.', str_replace('_', ' ', $role)));
            }
        }
    }

    public function accountMappings(): array
    {
        $settings = $this->settingsRepository->find(1);
        if (!$settings instanceof OrganizationSettings) {
            return array_fill_keys([
                'accounts_receivable',
                'revenue',
                'cash',
                'accounts_payable',
                'project_expense',
                'expense_payable',
                'inventory',
                'cost_of_sales',
                'goods_received_not_invoiced',
                'inventory_adjustment',
                'purchase_price_variance',
            ], null);
        }

        $result = [];
        foreach ($settings->getAccountMappings() as $role => $account) {
            $result[$role] = $account?->getId();
        }

        return $result;
    }

    /**
     * Run a source-record change and its journal posting in one database transaction.
     */
    public function runAtomically(callable $operation): mixed
    {
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $result = $operation();
            $connection->commit();

            return $result;
        } catch (\Throwable $exception) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            throw $exception;
        }
    }

    /** @param array<string, int|null> $accountIds */
    public function updateAccountMappings(array $accountIds): array
    {
        $settings = $this->settingsRepository->find(1);
        if (!$settings instanceof OrganizationSettings) {
            $settings = new OrganizationSettings();
            $this->entityManager->persist($settings);
        }

        $expectedTypes = [
            'accounts_receivable' => 'asset',
            'revenue' => 'revenue',
            'cash' => 'asset',
            'accounts_payable' => 'liability',
            'project_expense' => 'expense',
            'expense_payable' => 'liability',
            'inventory' => 'asset',
            'cost_of_sales' => 'expense',
            'goods_received_not_invoiced' => 'liability',
            'inventory_adjustment' => 'expense',
            'purchase_price_variance' => 'expense',
        ];
        $mappings = [];
        foreach ($expectedTypes as $role => $expectedType) {
            $id = $accountIds[$role] ?? null;
            if ($id === null) {
                $mappings[$role] = null;
                continue;
            }
            $account = $this->accountRepository->find($id);
            if (!$account instanceof LedgerAccount || !$account->isActive() || $account->getType() !== $expectedType) {
                throw new \InvalidArgumentException(sprintf('Select an active %s account for the %s mapping.', $expectedType, str_replace('_', ' ', $role)));
            }
            $mappings[$role] = $account;
        }

        $settings->setAccountMappings($mappings);
        $this->entityManager->flush();

        return $this->accountMappings();
    }
}
