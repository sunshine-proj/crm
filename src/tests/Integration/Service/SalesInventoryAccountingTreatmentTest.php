<?php

namespace App\Tests\Integration\Service;

use App\Entity\JournalEntry;
use App\Entity\Product;
use App\Entity\Warehouse;
use App\Service\StockLedger;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Pins the documented accounting treatment (docs/ACCOUNTING_TREATMENT.md): the stock ledger itself is quantity-only and
 * never creates journal entries. Valuation postings come only from InventoryValuationService, and only once the
 * inventory accounts are mapped (see InventoryValuationServiceTest).
 */
final class SalesInventoryAccountingTreatmentTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        putenv('DATABASE_URL=sqlite:///:memory:');
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        (new SchemaTool($this->entityManager))->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());
    }

    protected function tearDown(): void
    {
        $this->entityManager->close();
        parent::tearDown();
    }

    public function test_receipts_dispatches_and_adjustments_create_no_journal_entries(): void
    {
        $product = new Product('SKU-1', 'Widget', 'EUR', 1999);
        $warehouse = new Warehouse('MAIN', 'Main', null);
        $this->entityManager->persist($product);
        $this->entityManager->persist($warehouse);
        $this->entityManager->flush();
        $ledger = self::getContainer()->get(StockLedger::class);
        $day = new \DateTimeImmutable('2026-03-01');

        $ledger->record($product, $warehouse, 'receipt', 10, 'purchase_order', 1, $day, 'Goods receipt');
        $this->entityManager->flush();
        $ledger->record($product, $warehouse, 'dispatch', -4, 'sales_shipment', 1, $day, 'Dispatch');
        $this->entityManager->flush();
        $ledger->record($product, $warehouse, 'adjustment_out', -1, 'adjustment', null, $day, 'count');
        $this->entityManager->flush();

        self::assertSame(0, $this->entityManager->getRepository(JournalEntry::class)->count([]));
        self::assertSame(5, $ledger->onHand($product, $warehouse));
    }
}
