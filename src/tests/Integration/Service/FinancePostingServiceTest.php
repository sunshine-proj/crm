<?php

namespace App\Tests\Integration\Service;

use App\Entity\FiscalPeriod;
use App\Entity\JournalEntry;
use App\Entity\LedgerAccount;
use App\Service\FinancePostingService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class FinancePostingServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private FinancePostingService $posting;
    private FiscalPeriod $period;

    protected function setUp(): void
    {
        putenv('DATABASE_URL=sqlite:///:memory:');
        $_ENV['DATABASE_URL'] = 'sqlite:///:memory:';
        $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';

        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->posting = self::getContainer()->get(FinancePostingService::class);
        (new SchemaTool($this->entityManager))->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());

        $this->period = new FiscalPeriod('2026', new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31'));
        $accounts = [
            'ar' => new LedgerAccount('1200', 'Receivables', 'asset'),
            'revenue' => new LedgerAccount('4000', 'Revenue', 'revenue'),
            'cash' => new LedgerAccount('1000', 'Cash', 'asset'),
        ];
        $this->entityManager->persist($this->period);
        foreach ($accounts as $account) {
            $this->entityManager->persist($account);
        }
        $this->entityManager->flush();
        $this->posting->updateAccountMappings([
            'accounts_receivable' => $accounts['ar']->getId(),
            'revenue' => $accounts['revenue']->getId(),
            'cash' => $accounts['cash']->getId(),
        ]);
    }

    protected function tearDown(): void
    {
        $this->entityManager->close();
        parent::tearDown();
    }

    /** @return list<array{role: string, debit_minor: int, credit_minor: int, memo: string}> */
    private function invoiceLines(int $amount = 12345): array
    {
        return [
            ['role' => 'accounts_receivable', 'debit_minor' => $amount, 'credit_minor' => 0, 'memo' => 'AR'],
            ['role' => 'revenue', 'debit_minor' => 0, 'credit_minor' => $amount, 'memo' => 'Revenue'],
        ];
    }

    private function entryCount(): int
    {
        return $this->entityManager->getRepository(JournalEntry::class)->count([]);
    }

    public function test_a_balanced_posting_is_numbered_and_stores_integer_minor_units(): void
    {
        $entry = $this->posting->post('invoice_issue', 7, new \DateTimeImmutable('2026-03-01'), 'EUR', 'Invoice', $this->invoiceLines());

        self::assertSame('JE-2026-'.str_pad((string) $entry->getId(), 6, '0', STR_PAD_LEFT), $entry->getEntryNumber());
        self::assertCount(2, $entry->getLines());
        $debits = 0;
        $credits = 0;
        foreach ($entry->getLines() as $line) {
            $debits += $line->getDebitMinor();
            $credits += $line->getCreditMinor();
        }
        self::assertSame(12345, $debits);
        self::assertSame($debits, $credits);
    }

    public function test_posting_the_same_source_twice_is_idempotent(): void
    {
        $first = $this->posting->post('invoice_issue', 7, new \DateTimeImmutable('2026-03-01'), 'EUR', 'Invoice', $this->invoiceLines());
        $second = $this->posting->post('invoice_issue', 7, new \DateTimeImmutable('2026-03-01'), 'EUR', 'Invoice', $this->invoiceLines());

        self::assertSame($first->getId(), $second->getId());
        self::assertSame(1, $this->entryCount());
    }

    public function test_unbalanced_or_two_sided_lines_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $lines = $this->invoiceLines();
        $lines[1]['credit_minor'] = 12344;
        $this->posting->post('invoice_issue', 8, new \DateTimeImmutable('2026-03-01'), 'EUR', 'Invoice', $lines);
    }

    public function test_posting_outside_an_open_period_is_rejected(): void
    {
        try {
            $this->posting->post('invoice_issue', 9, new \DateTimeImmutable('2027-01-05'), 'EUR', 'Invoice', $this->invoiceLines());
            self::fail('A date outside every fiscal period must be rejected.');
        } catch (\DomainException) {
            self::assertSame(0, $this->entryCount());
        }

        $this->period->close('admin@example.test', 'Year end', new \DateTimeImmutable('2027-01-02'));
        $this->entityManager->flush();
        $this->expectException(\DomainException::class);
        $this->posting->assertCanPost(new \DateTimeImmutable('2026-03-01'), ['accounts_receivable']);
    }

    public function test_a_missing_account_mapping_blocks_posting(): void
    {
        $this->expectException(\DomainException::class);
        $this->posting->post('vendor_bill', 3, new \DateTimeImmutable('2026-03-01'), 'EUR', 'Bill', [
            ['role' => 'project_expense', 'debit_minor' => 100, 'credit_minor' => 0, 'memo' => 'Cost'],
            ['role' => 'accounts_payable', 'debit_minor' => 0, 'credit_minor' => 100, 'memo' => 'AP'],
        ]);
    }

    public function test_mapping_an_account_of_the_wrong_type_is_rejected(): void
    {
        $revenue = $this->entityManager->getRepository(LedgerAccount::class)->findOneBy(['code' => '4000']);

        $this->expectException(\InvalidArgumentException::class);
        $this->posting->updateAccountMappings(['cash' => $revenue->getId()]);
    }

    public function test_a_failure_after_posting_rolls_back_the_journal_with_the_source_change(): void
    {
        try {
            $this->posting->runAtomically(function (): void {
                $this->posting->post('customer_receipt', 5, new \DateTimeImmutable('2026-03-01'), 'EUR', 'Receipt', [
                    ['role' => 'cash', 'debit_minor' => 500, 'credit_minor' => 0, 'memo' => 'Cash'],
                    ['role' => 'accounts_receivable', 'debit_minor' => 0, 'credit_minor' => 500, 'memo' => 'AR'],
                ]);
                throw new \RuntimeException('source update failed');
            });
            self::fail('The operation should have failed.');
        } catch (\RuntimeException) {
            self::assertSame(0, $this->entryCount());
        }
    }
}
