<?php

namespace App\Tests\Integration\Service;

use App\Entity\FiscalPeriod;
use App\Entity\GoodsReceipt;
use App\Entity\JournalEntry;
use App\Entity\LedgerAccount;
use App\Entity\Product;
use App\Entity\PurchaseOrder;
use App\Entity\Supplier;
use App\Entity\Warehouse;
use App\Service\FinancePostingService;
use App\Service\InventoryValuationService;
use App\Service\LedgerReconciliationService;
use App\Service\StockLedger;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class InventoryValuationServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private InventoryValuationService $valuation;
    private Product $product;
    private Warehouse $warehouse;
    private Supplier $supplier;
    /** @var array<string, LedgerAccount> */
    private array $accounts;
    private \DateTimeImmutable $day;

    protected function setUp(): void
    {
        putenv('DATABASE_URL=sqlite:///:memory:');
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->valuation = $container->get(InventoryValuationService::class);
        (new SchemaTool($this->entityManager))->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());

        $this->day = new \DateTimeImmutable('2026-03-01');
        $this->accounts = [
            'inventory' => new LedgerAccount('1300', 'Inventory', 'asset'),
            'cost_of_sales' => new LedgerAccount('5100', 'Cost of sales', 'expense'),
            'goods_received_not_invoiced' => new LedgerAccount('2150', 'GR/IR', 'liability'),
            'inventory_adjustment' => new LedgerAccount('5200', 'Inventory adjustments', 'expense'),
            'purchase_price_variance' => new LedgerAccount('5300', 'Price variance', 'expense'),
            'accounts_payable' => new LedgerAccount('2000', 'Payables', 'liability'),
        ];
        $this->product = new Product('SKU-1', 'Widget', 'EUR', 5000);
        $this->warehouse = new Warehouse('MAIN', 'Main', null);
        $this->supplier = new Supplier('SUP', 'Supplier Ltd', null, null, null);
        $this->entityManager->persist(new FiscalPeriod('2026', new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31')));
        foreach ([...array_values($this->accounts), $this->product, $this->warehouse, $this->supplier] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        $this->entityManager->close();
        parent::tearDown();
    }

    private function mapAccounts(bool $all = true): void
    {
        $ids = array_map(static fn (LedgerAccount $account): ?int => $account->getId(), $this->accounts);
        if (!$all) {
            $ids['inventory'] = null;
        }
        self::getContainer()->get(FinancePostingService::class)->updateAccountMappings($ids);
    }

    /** Receives $quantity units at $unitCost (minor units) with optional landed cost; returns the receipt. */
    private function receive(int $quantity, int $unitCost, int $landed = 0, string $currency = 'EUR'): GoodsReceipt
    {
        $order = new PurchaseOrder($this->supplier, $currency, [['product_id' => $this->product->getId(), 'sku' => 'SKU-1', 'description' => 'Widget', 'quantity' => $quantity, 'unit_cost_minor' => $unitCost, 'line_total_minor' => $quantity * $unitCost]], $quantity * $unitCost, $this->day);
        $this->entityManager->persist($order);
        $receipt = new GoodsReceipt($order, $this->warehouse, [['product_id' => $this->product->getId(), 'description' => 'Widget', 'quantity' => $quantity]], $this->day, null);
        if ($landed > 0) {
            $receipt->withLandedCosts([['description' => 'Freight', 'amount_minor' => $landed]]);
        }
        $this->entityManager->persist($receipt);
        $this->entityManager->flush();
        self::getContainer()->get(StockLedger::class)->record($this->product, $this->warehouse, 'goods_receipt', $quantity, 'goods_receipt', $receipt->getId(), $this->day, 'Receipt');
        $this->entityManager->flush();
        $this->valuation->recordReceipt($receipt, $currency, [['product' => $this->product, 'quantity' => $quantity, 'goods_minor' => $quantity * $unitCost, 'landed_minor' => $landed]]);

        return $receipt;
    }

    /** @return array{debit: int, credit: int} net movement on one mapped account across all journals */
    private function balance(string $role): array
    {
        $debit = 0;
        $credit = 0;
        foreach ($this->entityManager->getRepository(JournalEntry::class)->findAll() as $entry) {
            foreach ($entry->getLines() as $line) {
                if ($line->getAccount()->getId() === $this->accounts[$role]->getId()) {
                    $debit += $line->getDebitMinor();
                    $credit += $line->getCreditMinor();
                }
            }
        }

        return ['debit' => $debit, 'credit' => $credit];
    }

    public function test_valuation_is_off_until_the_accounts_are_mapped(): void
    {
        self::assertFalse($this->valuation->isEnabled());
        $this->mapAccounts(false);
        self::assertFalse($this->valuation->isEnabled());
        $this->mapAccounts();
        self::assertTrue($this->valuation->isEnabled());
    }

    public function test_a_receipt_capitalises_goods_and_landed_cost_against_goods_received_not_invoiced(): void
    {
        $this->mapAccounts();
        $this->receive(10, 1000, 555);

        $pool = $this->valuation->pool($this->product, $this->warehouse);
        self::assertSame(10, $pool['quantity']);
        self::assertSame(10555, $pool['value']);
        self::assertSame(['debit' => 10555, 'credit' => 0], $this->balance('inventory'));
        self::assertSame(['debit' => 0, 'credit' => 10555], $this->balance('goods_received_not_invoiced'));
    }

    public function test_dispatch_leaves_at_weighted_average_cost_and_the_last_unit_takes_the_exact_remainder(): void
    {
        $this->mapAccounts();
        $this->receive(10, 1000);           // 10 @ 10.00 = 100.00
        $this->receive(10, 1300);           // 10 @ 13.00 -> pool 20 units, 230.00, average 11.50

        $first = $this->valuation->recordDispatch(1, $this->product, $this->warehouse, 7, $this->day);
        self::assertSame(-8050, $first->getValueMinor(), '7 x 11.50');
        $pool = $this->valuation->pool($this->product, $this->warehouse);
        self::assertSame([13, 14950], [$pool['quantity'], $pool['value']]);

        $this->valuation->recordDispatch(2, $this->product, $this->warehouse, 13, $this->day);
        $pool = $this->valuation->pool($this->product, $this->warehouse);
        self::assertSame([0, 0], [$pool['quantity'], $pool['value']], 'the pool empties to exactly zero');
        self::assertSame(['debit' => 23000, 'credit' => 0], $this->balance('cost_of_sales'));
        self::assertSame($this->balance('inventory')['debit'], $this->balance('inventory')['credit'] + 0, 'inventory net is nil after everything leaves');
    }

    public function test_a_customer_return_goes_back_at_the_dispatch_cost_not_the_current_average(): void
    {
        $this->mapAccounts();
        $this->receive(10, 1000);
        $this->valuation->recordDispatch(5, $this->product, $this->warehouse, 4, $this->day);   // 4 x 10.00
        $this->receive(10, 2000);                                                                // average rises

        $entry = $this->valuation->recordCustomerReturn(9, 5, $this->product, $this->warehouse, 2, $this->day);

        self::assertSame(2000, $entry->getValueMinor(), '2 x the 10.00 the dispatch removed');
        self::assertSame(['debit' => 4000, 'credit' => 2000], $this->balance('cost_of_sales'));
    }

    public function test_a_return_of_an_unvalued_dispatch_is_not_valued(): void
    {
        $this->mapAccounts();
        self::assertNull($this->valuation->recordCustomerReturn(1, 99, $this->product, $this->warehouse, 1, $this->day));
    }

    public function test_supplier_returns_and_count_adjustments_use_average_cost(): void
    {
        $this->mapAccounts();
        $this->receive(10, 1000);

        $this->valuation->recordSupplierReturn(1, $this->product, $this->warehouse, 2, $this->day);
        self::assertSame(['debit' => 2000, 'credit' => 10000], $this->balance('goods_received_not_invoiced'));

        $loss = $this->valuation->recordAdjustment(11, $this->product, $this->warehouse, -1, $this->day, null, null);
        self::assertSame(-1000, $loss->getValueMinor());
        $gain = $this->valuation->recordAdjustment(12, $this->product, $this->warehouse, 2, $this->day, null, null);
        self::assertSame(2000, $gain->getValueMinor());
        self::assertSame(['debit' => 1000, 'credit' => 2000], $this->balance('inventory_adjustment'));
    }

    public function test_extra_stock_in_an_empty_pool_needs_a_unit_cost(): void
    {
        $this->mapAccounts();
        try {
            $this->valuation->recordAdjustment(1, $this->product, $this->warehouse, 3, $this->day, null, null);
            self::fail('A cost is required.');
        } catch (\DomainException) {
        }
        $entry = $this->valuation->recordAdjustment(2, $this->product, $this->warehouse, 3, $this->day, 750, 'EUR');
        self::assertSame(2250, $entry->getValueMinor());
    }

    public function test_stock_in_a_different_currency_cannot_be_mixed_into_a_pool(): void
    {
        $this->mapAccounts();
        $this->receive(5, 1000);

        $this->expectException(\DomainException::class);
        $this->receive(5, 1000, 0, 'GBP');
    }

    public function test_issuing_more_than_the_valued_quantity_is_refused_until_legacy_stock_gets_an_opening_valuation(): void
    {
        // 6 units were received before valuation existed: quantity-only movements.
        $ledger = self::getContainer()->get(StockLedger::class);
        $ledger->record($this->product, $this->warehouse, 'goods_receipt', 6, 'goods_receipt', 1, $this->day, 'Legacy');
        $this->entityManager->flush();
        $this->mapAccounts();

        try {
            $this->valuation->recordDispatch(1, $this->product, $this->warehouse, 2, $this->day);
            self::fail('Legacy stock has no cost yet.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('opening valuation', $exception->getMessage());
        }

        $opening = $this->valuation->recordOpening($this->product, $this->warehouse, 6, 900, 'EUR', $this->day);
        self::assertSame(5400, $opening->getValueMinor());
        self::assertSame(['debit' => 5400, 'credit' => 0], $this->balance('inventory'));
        self::assertSame(-1800, $this->valuation->recordDispatch(1, $this->product, $this->warehouse, 2, $this->day)->getValueMinor());

        $this->expectException(\DomainException::class);
        $this->valuation->recordOpening($this->product, $this->warehouse, 6, 900, 'EUR', $this->day);
    }

    public function test_landed_costs_are_allocated_exactly_by_line_value(): void
    {
        $shares = $this->valuation->allocateLandedCosts([0 => 3000, 1 => 1000, 2 => 1000], 1001);
        self::assertSame(1001, array_sum($shares));
        self::assertSame([601, 200, 200], array_values($shares), 'the odd minor unit goes to the largest remainder, earlier line first');

        self::assertSame([5, 5], array_values($this->valuation->allocateLandedCosts([0 => 0, 1 => 0], 10)), 'zero-value lines split evenly');
        self::assertSame([10], array_values($this->valuation->allocateLandedCosts([7 => 123], 10)));
    }

    public function test_proportions_round_half_up_and_refuse_overflow(): void
    {
        self::assertSame(3, $this->valuation->proportion(10, 1, 3));
        self::assertSame(3, $this->valuation->proportion(5, 1, 2));
        $this->expectException(\OverflowException::class);
        $this->valuation->proportion(PHP_INT_MAX, 2, 3);
    }

    public function test_the_inventory_and_goods_received_controls_reconcile_to_the_ledger(): void
    {
        $this->mapAccounts();
        $this->receive(10, 1000, 100);
        $this->valuation->recordDispatch(1, $this->product, $this->warehouse, 3, $this->day);
        $this->valuation->recordSupplierReturn(1, $this->product, $this->warehouse, 1, $this->day);

        $result = self::getContainer()->get(LedgerReconciliationService::class)->reconcile('EUR');

        self::assertSame($result['inventory']['subledger_minor'], $result['inventory']['ledger_minor']);
        self::assertSame(0, $result['inventory']['difference_minor']);
        self::assertSame(0, $result['goods_received_not_invoiced']['difference_minor']);
        self::assertSame([], $result['missing']);
        self::assertSame([], $result['amount_mismatches']);
    }
}
