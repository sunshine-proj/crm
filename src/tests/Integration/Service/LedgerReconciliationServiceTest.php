<?php

namespace App\Tests\Integration\Service;

use App\Entity\FiscalPeriod;
use App\Entity\LedgerAccount;
use App\Entity\PayableBill;
use App\Entity\PayablePayment;
use App\Service\FinancePostingService;
use App\Service\LedgerReconciliationService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class LedgerReconciliationServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private FinancePostingService $posting;
    private LedgerReconciliationService $reconciliation;

    protected function setUp(): void
    {
        putenv('DATABASE_URL=sqlite:///:memory:');
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->posting = $container->get(FinancePostingService::class);
        $this->reconciliation = $container->get(LedgerReconciliationService::class);
        (new SchemaTool($this->entityManager))->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());

        $accounts = [
            'ar' => new LedgerAccount('1200', 'Receivables', 'asset'),
            'revenue' => new LedgerAccount('4000', 'Revenue', 'revenue'),
            'cash' => new LedgerAccount('1000', 'Cash', 'asset'),
            'ap' => new LedgerAccount('2000', 'Payables', 'liability'),
            'expense' => new LedgerAccount('5000', 'Expense', 'expense'),
            'expense_payable' => new LedgerAccount('2100', 'Expense payable', 'liability'),
        ];
        $this->entityManager->persist(new FiscalPeriod('2026', new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31')));
        foreach ($accounts as $account) {
            $this->entityManager->persist($account);
        }
        $this->entityManager->flush();
        $this->posting->updateAccountMappings([
            'accounts_receivable' => $accounts['ar']->getId(), 'revenue' => $accounts['revenue']->getId(), 'cash' => $accounts['cash']->getId(),
            'accounts_payable' => $accounts['ap']->getId(), 'project_expense' => $accounts['expense']->getId(), 'expense_payable' => $accounts['expense_payable']->getId(),
        ]);
    }

    protected function tearDown(): void
    {
        $this->entityManager->close();
        parent::tearDown();
    }

    private function postedBill(string $reference, int $amount, ?int $postedAmount = null): PayableBill
    {
        $bill = new PayableBill('Vendor', $reference, 'EUR', $amount, new \DateTimeImmutable('2026-02-01'), null);
        $this->entityManager->persist($bill);
        $this->entityManager->flush();
        $posted = $postedAmount ?? $amount;
        $this->posting->post('vendor_bill', (int) $bill->getId(), $bill->getBillDate(), 'EUR', 'Bill '.$reference, [
            ['role' => 'project_expense', 'debit_minor' => $posted, 'credit_minor' => 0, 'memo' => 'Cost'],
            ['role' => 'accounts_payable', 'debit_minor' => 0, 'credit_minor' => $posted, 'memo' => 'AP'],
        ]);

        return $bill;
    }

    public function test_matching_sub_ledger_and_ledger_reconcile(): void
    {
        $bill = $this->postedBill('B-1', 10000);
        $payment = new PayablePayment($bill, 4000, new \DateTimeImmutable('2026-02-10'), null);
        $this->entityManager->persist($payment);
        $this->entityManager->flush();
        $this->posting->post('vendor_payment', (int) $payment->getId(), $payment->getPaidAt(), 'EUR', 'Pay', [
            ['role' => 'accounts_payable', 'debit_minor' => 4000, 'credit_minor' => 0, 'memo' => 'AP'],
            ['role' => 'cash', 'debit_minor' => 0, 'credit_minor' => 4000, 'memo' => 'Cash'],
        ]);

        $result = $this->reconciliation->reconcile('EUR');

        self::assertSame(6000, $result['payables']['subledger_minor']);
        self::assertSame(6000, $result['payables']['ledger_minor']);
        self::assertSame(0, $result['payables']['difference_minor']);
        self::assertSame(0, $result['receivables']['difference_minor']);
        self::assertTrue($result['reconciled']);
    }

    public function test_a_bill_without_a_posting_is_reported_missing_and_breaks_the_control_balance(): void
    {
        $this->entityManager->persist(new PayableBill('Vendor', 'B-2', 'EUR', 2500, new \DateTimeImmutable('2026-02-01'), null));
        $this->entityManager->flush();

        $result = $this->reconciliation->reconcile('EUR');

        self::assertCount(1, $result['missing']);
        self::assertSame(2500, $result['missing'][0]['expected_minor']);
        self::assertSame(-2500, $result['payables']['difference_minor']);
        self::assertFalse($result['reconciled']);
    }

    public function test_a_posting_with_a_different_amount_than_its_source_is_a_mismatch(): void
    {
        $this->postedBill('B-3', 3000, 2900);

        $result = $this->reconciliation->reconcile('EUR');

        self::assertCount(1, $result['amount_mismatches']);
        self::assertSame(3000, $result['amount_mismatches'][0]['expected_minor']);
        self::assertSame(2900, $result['amount_mismatches'][0]['posted_minor']);
        self::assertFalse($result['reconciled']);
    }

    public function test_a_posting_whose_source_no_longer_exists_is_orphaned(): void
    {
        $bill = $this->postedBill('B-4', 1000);
        $this->entityManager->remove($bill);
        $this->entityManager->flush();

        $result = $this->reconciliation->reconcile('EUR');

        self::assertCount(1, $result['orphaned']);
        self::assertSame('vendor_bill', $result['orphaned'][0]['source_type']);
        self::assertFalse($result['reconciled']);
    }

    public function test_other_currencies_are_excluded(): void
    {
        $this->postedBill('B-5', 5000);

        $result = $this->reconciliation->reconcile('GBP');

        self::assertSame(0, $result['payables']['subledger_minor']);
        self::assertTrue($result['reconciled']);
    }
}
