<?php

namespace App\Tests\Integration\Service;

use App\Entity\FiscalPeriod;
use App\Entity\GoodsReceipt;
use App\Entity\InventoryReturn;
use App\Entity\LedgerAccount;
use App\Entity\PayableBill;
use App\Entity\Product;
use App\Entity\PurchaseOrder;
use App\Entity\Supplier;
use App\Entity\Warehouse;
use App\Service\FinancePostingService;
use App\Service\InventoryValuationService;
use App\Service\PurchaseMatchingService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PurchaseMatchingServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private PurchaseMatchingService $matching;
    private PurchaseOrder $order;
    private Product $product;
    private Warehouse $warehouse;
    private \DateTimeImmutable $day;

    protected function setUp(): void
    {
        putenv('DATABASE_URL=sqlite:///:memory:');
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->matching = self::getContainer()->get(PurchaseMatchingService::class);
        (new SchemaTool($this->entityManager))->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());
        $this->day = new \DateTimeImmutable('2026-03-01');

        $this->product = new Product('SKU-1', 'Widget', 'EUR', 5000);
        $this->warehouse = new Warehouse('MAIN', 'Main', null);
        $supplier = new Supplier('SUP', 'Supplier Ltd', null, null, null);
        foreach ([$this->product, $this->warehouse, $supplier] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
        $this->order = new PurchaseOrder($supplier, 'EUR', [['product_id' => $this->product->getId(), 'sku' => 'SKU-1', 'description' => 'Widget', 'quantity' => 10, 'unit_cost_minor' => 1000, 'line_total_minor' => 10000]], 10000, $this->day);
        $this->entityManager->persist($this->order);
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        $this->entityManager->close();
        parent::tearDown();
    }

    private function receive(int $quantity, int $landed = 0): GoodsReceipt
    {
        $receipt = new GoodsReceipt($this->order, $this->warehouse, [['product_id' => $this->product->getId(), 'description' => 'Widget', 'quantity' => $quantity]], $this->day, null);
        if ($landed > 0) {
            $receipt->withLandedCosts([['description' => 'Freight', 'amount_minor' => $landed]]);
        }
        $this->entityManager->persist($receipt);
        $this->entityManager->flush();

        return $receipt;
    }

    private function bill(int $total, string $reference, int $cleared = 0): PayableBill
    {
        $bill = (new PayableBill('Supplier Ltd', $reference, 'EUR', $total, $this->day, null));
        $bill->matchTo($this->order, 'matched', null, $cleared, 0);
        $this->entityManager->persist($bill);
        $this->entityManager->flush();

        return $bill;
    }

    public function test_received_value_uses_order_prices_plus_landed_cost_and_nets_supplier_returns(): void
    {
        $this->receive(6, 300);
        $this->entityManager->persist(new InventoryReturn('supplier', $this->warehouse, 'purchase_order', (int) $this->order->getId(), [['product_id' => $this->product->getId(), 'description' => 'Widget', 'quantity' => 1]], $this->day, null));
        $this->entityManager->flush();

        self::assertSame(6000 + 300 - 1000, $this->matching->receivedValueMinor($this->order));
    }

    public function test_a_bill_within_the_received_not_yet_billed_value_matches(): void
    {
        $this->receive(6);
        $result = $this->matching->evaluate($this->order, 6000, null);

        self::assertSame('matched', $result['status']);
        self::assertSame(6000, $result['allowed_minor']);
    }

    public function test_a_bill_for_goods_not_received_needs_an_override_reason(): void
    {
        $this->receive(6);
        try {
            $this->matching->evaluate($this->order, 6001, null);
            self::fail('The bill exceeds what was received.');
        } catch (\DomainException) {
        }

        $result = $this->matching->evaluate($this->order, 10000, 'Supplier prepayment agreed');
        self::assertSame('exception', $result['status']);
        self::assertSame('Supplier prepayment agreed', $result['note']);
    }

    public function test_earlier_bills_use_up_the_allowance(): void
    {
        $this->receive(6);
        $this->bill(4000, 'B-1');

        self::assertSame('matched', $this->matching->evaluate($this->order, 2000, null)['status']);
        $this->expectException(\DomainException::class);
        $this->matching->evaluate($this->order, 2001, '  ');
    }

    public function test_the_accrual_stays_open_until_bills_clear_it(): void
    {
        $accounts = [
            'inventory' => new LedgerAccount('1300', 'Inventory', 'asset'),
            'cost_of_sales' => new LedgerAccount('5100', 'COGS', 'expense'),
            'goods_received_not_invoiced' => new LedgerAccount('2150', 'GR/IR', 'liability'),
            'inventory_adjustment' => new LedgerAccount('5200', 'Adjustments', 'expense'),
        ];
        $this->entityManager->persist(new FiscalPeriod('2026', new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31')));
        foreach ($accounts as $account) {
            $this->entityManager->persist($account);
        }
        $this->entityManager->flush();
        self::getContainer()->get(FinancePostingService::class)->updateAccountMappings(array_map(static fn (LedgerAccount $a): ?int => $a->getId(), $accounts));
        $receipt = $this->receive(6, 300);
        self::assertFalse($this->matching->hasValuedReceipts($this->order));

        self::getContainer()->get(InventoryValuationService::class)->recordReceipt($receipt, 'EUR', [['product' => $this->product, 'quantity' => 6, 'goods_minor' => 6000, 'landed_minor' => 300]]);

        self::assertTrue($this->matching->hasValuedReceipts($this->order));
        self::assertSame(6300, $this->matching->accrualRemainingMinor($this->order));
        $this->bill(2500, 'B-2', 2500);
        self::assertSame(3800, $this->matching->accrualRemainingMinor($this->order));
    }
}
