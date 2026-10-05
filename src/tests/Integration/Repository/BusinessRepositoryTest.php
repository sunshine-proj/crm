<?php

namespace App\Tests\Integration\Repository;

use App\Entity\Activity;
use App\Entity\Client;
use App\Entity\ClientContact;
use App\Entity\Expense;
use App\Entity\ExpenseApproval;
use App\Entity\Invoice;
use App\Entity\GoodsReceipt;
use App\Entity\FiscalPeriod;
use App\Entity\InventoryReturn;
use App\Entity\JournalEntry;
use App\Entity\JournalEntryLine;
use App\Entity\LedgerAccount;
use App\Entity\Note;
use App\Entity\PayableBill;
use App\Entity\PayablePayment;
use App\Entity\Project;
use App\Entity\ProjectDocument;
use App\Entity\PurchaseOrder;
use App\Entity\ProjectStatus;
use App\Entity\Product;
use App\Entity\SalesDocument;
use App\Entity\Shipment;
use App\Entity\Payment;
use App\Entity\Task;
use App\Entity\TaskStatus;
use App\Entity\TaxCode;
use App\Entity\TimeEntry;
use App\Entity\StockMovement;
use App\Entity\Supplier;
use App\Entity\User;
use App\Entity\Warehouse;
use App\Repository\ClientRepository;
use App\Repository\ProjectRepository;
use App\Repository\TaskRepository;
use App\Service\ClientActivityLogger;
use App\Service\StockLedger;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BusinessRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        putenv('DATABASE_URL=sqlite:///:memory:');
        $_ENV['DATABASE_URL'] = 'sqlite:///:memory:';
        $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';

        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->createSchema($metadata);
    }

    protected function tearDown(): void
    {
        $this->entityManager->close();
        parent::tearDown();
    }

    public function test_linked_records_persist_and_dashboard_counts_reflect_status(): void
    {
        $now = new DateTimeImmutable();
        $client = (new Client())
            ->setTitle('Acme')
            ->setNotes('Primary account')
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $contact = (new ClientContact())
            ->setClient($client)
            ->setName('Morgan Lee')
            ->setEmail('morgan@example.com')
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $project = (new Project())
            ->setTitle('Onboarding')
            ->setDescription('Customer implementation')
            ->setClient($client)
            ->setBudget(250000, 'EUR')
            ->setStatus(ProjectStatus::Active)
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $manager = (new User())
            ->setEmail('manager@example.com')
            ->setPasswordHash('not-a-real-password-hash');
        $project->setManager($manager);
        $invoice = (new Invoice())
            ->setInvoiceNumber('INV-2026-000001')
            ->setDetails(
                $client,
                $project,
                $manager,
                'Acme',
                'Onboarding',
                'manager@example.com',
                new DateTimeImmutable('2026-10-02'),
                null,
                'EUR',
                [['task_id' => null, 'description' => 'Kickoff', 'amount' => '150.00']],
                15000,
            );
        $openTask = (new Task())
            ->setTitle('Kickoff')
            ->setDescription('Schedule the kickoff')
            ->setProject($project)
            ->setStatus(TaskStatus::InProgress)
            ->setSchedule($now, $now->modify('+1 hour'))
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $completedTask = (new Task())
            ->setTitle('Prepare documents')
            ->setDescription('Prepare the welcome pack')
            ->setProject($project)
            ->setStatus(TaskStatus::Done)
            ->setSchedule($now, $now->modify('+2 hours'))
            ->setCreatedAt($now)
            ->setUpdatedAt($now);

        $this->entityManager->persist($client);
        $this->entityManager->persist($manager);
        $this->entityManager->persist($contact);
        $this->entityManager->persist($project);
        $this->entityManager->persist($invoice);
        $this->entityManager->persist($openTask);
        $this->entityManager->persist($completedTask);
        $this->entityManager->flush();
        $this->entityManager->clear();

        self::assertInstanceOf(ClientRepository::class, $this->entityManager->getRepository(Client::class));
        self::assertSame(1, $this->entityManager->getRepository(Client::class)->count([]));
        $storedContact = $this->entityManager->getRepository(ClientContact::class)->find($contact->getId());
        self::assertSame('Acme', $storedContact?->getClient()?->getTitle());
        self::assertSame('Morgan Lee', $storedContact?->getName());
        self::assertSame(1, $this->entityManager->getRepository(Project::class)->countWithStatus(ProjectStatus::Active));
        self::assertSame(1, $this->entityManager->getRepository(Task::class)->countOpen());

        $storedTask = $this->entityManager->getRepository(Task::class)->find($openTask->getId());
        self::assertSame('Onboarding', $storedTask?->getProject()?->getTitle());
        self::assertSame('Acme', $storedTask?->getProject()?->getClient()?->getTitle());
        self::assertSame(250000, $storedTask?->getProject()?->getBudgetMinor());
        self::assertSame('EUR', $storedTask?->getProject()?->getBudgetCurrency());
        self::assertSame('manager@example.com', $storedTask?->getProject()?->getManager()?->getUserIdentifier());

        $filteredInvoices = $this->entityManager->getRepository(Invoice::class)->findFiltered([
            'client_id' => $client->getId(),
            'project_id' => $project->getId(),
            'manager_id' => $manager->getId(),
            'from' => new DateTimeImmutable('2026-10-01'),
            'to' => new DateTimeImmutable('2026-10-03'),
            'currency' => 'EUR',
            'min_minor' => 15000,
            'max_minor' => 15000,
        ]);
        self::assertCount(1, $filteredInvoices);
        self::assertSame(15000, $filteredInvoices[0]->getTotalMinor());
        self::assertSame('150.00', $filteredInvoices[0]->getItems()[0]['amount']);
    }

    public function test_project_notes_persist_with_author_metadata(): void
    {
        $now = new DateTimeImmutable();
        $user = (new User())
            ->setEmail('owner@example.com')
            ->setPasswordHash('not-a-real-password-hash');
        $client = (new Client())
            ->setTitle('Northwind Co.')
            ->setNotes('Key customer')
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $project = (new Project())
            ->setTitle('Migration')
            ->setDescription('System migration')
            ->setClient($client)
            ->setStatus(ProjectStatus::Planned)
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $note = (new Note())
            ->setEntityType('project')
            ->setEntityId($project->getId() ?? 0)
            ->setContent('Discuss rollout timeline with the client.')
            ->setAuthor($user)
            ->setCreatedAt($now)
            ->setUpdatedAt($now);

        $this->entityManager->persist($user);
        $this->entityManager->persist($client);
        $this->entityManager->persist($project);
        $this->entityManager->persist($note);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $storedNote = $this->entityManager->getRepository(Note::class)->find($note->getId());

        self::assertNotNull($storedNote);
        self::assertSame('project', $storedNote->getEntityType());
        self::assertSame('Discuss rollout timeline with the client.', $storedNote->getContent());
        self::assertSame('owner@example.com', $storedNote->getAuthor()?->getUserIdentifier());
    }

    public function test_client_activity_history_tracks_recent_events(): void
    {
        $now = new DateTimeImmutable();
        $user = (new User())
            ->setEmail('sales@example.com')
            ->setPasswordHash('not-a-real-password-hash');
        $client = (new Client())
            ->setTitle('Cobalt Labs')
            ->setNotes('Enterprise customer')
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $activity = (new Activity())
            ->setClient($client)
            ->setAuthor($user)
            ->setType('project_created')
            ->setMessage('Project onboarding plan created.')
            ->setCreatedAt($now)
            ->setUpdatedAt($now);

        $this->entityManager->persist($user);
        $this->entityManager->persist($client);
        $this->entityManager->persist($activity);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $storedActivity = $this->entityManager->getRepository(Activity::class)->find($activity->getId());

        self::assertNotNull($storedActivity);
        self::assertSame('Cobalt Labs', $storedActivity->getClient()?->getTitle());
        self::assertSame('project_created', $storedActivity->getType());
        self::assertSame('Project onboarding plan created.', $storedActivity->getMessage());
        self::assertSame('sales@example.com', $storedActivity->getAuthor()?->getUserIdentifier());
    }

    public function test_client_activity_logger_queues_lifecycle_entries_with_author(): void
    {
        $now = new DateTimeImmutable();
        $user = (new User())
            ->setEmail('sales@example.com')
            ->setPasswordHash('not-a-real-password-hash');
        $client = (new Client())
            ->setTitle('Cobalt Labs')
            ->setNotes('Enterprise customer')
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $this->entityManager->persist($user);
        $this->entityManager->persist($client);
        $this->entityManager->flush();

        $logger = new ClientActivityLogger($this->entityManager);
        $logger->record($client, $user, 'project_created', 'Project "Onboarding" was created.');
        $logger->record(null, $user, 'task_created', 'Task without a client should not be logged.');
        $this->entityManager->flush();
        $this->entityManager->clear();

        $activities = self::getContainer()->get(\App\Repository\ActivityRepository::class)->findByClient($client->getId());

        self::assertCount(1, $activities);
        self::assertSame('project_created', $activities[0]->getType());
        self::assertSame('Project "Onboarding" was created.', $activities[0]->getMessage());
        self::assertSame('sales@example.com', $activities[0]->getAuthor()?->getUserIdentifier());
    }

    public function test_sales_documents_snapshot_product_pricing_and_related_records(): void
    {
        $now = new DateTimeImmutable();
        $client = (new Client())
            ->setTitle('Cobalt Labs')
            ->setNotes('Enterprise customer')
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $product = new Product('CONSULT-01', 'Consulting day', 'EUR', 125000);
        $quote = new SalesDocument('quote', $client, 'EUR', [[
            'product_id' => 1,
            'sku' => 'CONSULT-01',
            'description' => 'Consulting day',
            'quantity' => 2,
            'unit_price_minor' => 125000,
            'line_total_minor' => 250000,
        ]], 250000);
        $order = new SalesDocument('sales_order', $client, 'EUR', $quote->getItems(), 250000, null, $quote);
        $this->entityManager->persist($client);
        $this->entityManager->persist($product);
        $this->entityManager->persist($quote);
        $this->entityManager->persist($order);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $storedOrder = $this->entityManager->getRepository(SalesDocument::class)->find($order->getId());

        self::assertNotNull($storedOrder);
        self::assertSame('sales_order', $storedOrder->getType());
        self::assertSame('Consulting day', $storedOrder->getItems()[0]['description']);
        self::assertSame(250000, $storedOrder->getTotalMinor());
        self::assertSame($quote->getId(), $storedOrder->getSourceQuote()?->getId());
    }

    public function test_shipments_and_partial_payments_persist_as_separate_records(): void
    {
        $now = new DateTimeImmutable();
        $client = (new Client())
            ->setTitle('Northstar Services')
            ->setNotes('Sales account')
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $order = new SalesDocument('sales_order', $client, 'EUR', [[
            'product_id' => 42,
            'sku' => 'SUPPORT-01',
            'description' => 'Support package',
            'quantity' => 3,
            'unit_price_minor' => 10000,
            'line_total_minor' => 30000,
        ]], 30000);
        $invoice = (new Invoice())->setDetails(
            $client,
            null,
            null,
            'Northstar Services',
            'Sales order',
            null,
            $now,
            null,
            'EUR',
            [['task_id' => null, 'description' => 'Support package', 'amount' => '300.00']],
            30000,
        )->setSalesOrder($order)->setStatus('partially_paid');
        $shipment = new Shipment($order, [['product_id' => 42, 'description' => 'Support package', 'quantity' => 2]], $now, 'dispatch-001');
        $firstPayment = new Payment($invoice, 12500, $now, 'bank-001');
        $secondPayment = new Payment($invoice, 7500, $now, 'bank-002');
        foreach ([$client, $order, $invoice, $shipment, $firstPayment, $secondPayment] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        $paymentRepository = self::getContainer()->get(\App\Repository\PaymentRepository::class);
        $shipmentRepository = self::getContainer()->get(\App\Repository\ShipmentRepository::class);

        self::assertSame(20000, $paymentRepository->sumMinorForInvoice((int) $invoice->getId()));
        self::assertCount(2, $paymentRepository->findForInvoice((int) $invoice->getId()));
        self::assertSame('dispatch-001', $shipmentRepository->findForOrder((int) $order->getId())[0]->getReference());
    }

    public function test_finance_accounts_periods_journal_and_payables_persist(): void
    {
        $startsOn = new DateTimeImmutable('2026-01-01');
        $endsOn = new DateTimeImmutable('2026-12-31');
        $period = new FiscalPeriod('FY2026', $startsOn, $endsOn);
        $bank = new LedgerAccount('1000', 'Operating bank', 'asset');
        $revenue = new LedgerAccount('4000', 'Service revenue', 'revenue');
        $entry = new JournalEntry($period, new DateTimeImmutable('2026-10-05'), 'EUR', 'Opening test entry');
        $entry->addLine(new JournalEntryLine($entry, $bank, 25000, 0, 'Cash received'));
        $entry->addLine(new JournalEntryLine($entry, $revenue, 0, 25000, 'Service income'));
        $taxCode = new TaxCode('VAT21', 'Standard VAT', 2100);
        $bill = new PayableBill('Paper supplier', 'PS-100', 'EUR', 12500, new DateTimeImmutable('2026-10-01'), new DateTimeImmutable('2026-10-31'));
        $billPayment = new PayablePayment($bill, 5000, new DateTimeImmutable('2026-10-03'), 'bank-ref-1');
        foreach ([$period, $bank, $revenue, $entry, $taxCode, $bill, $billPayment] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
        $this->entityManager->clear();

        $storedEntry = $this->entityManager->getRepository(JournalEntry::class)->find($entry->getId());
        $storedBill = $this->entityManager->getRepository(PayableBill::class)->find($bill->getId());
        $accountRepository = self::getContainer()->get(\App\Repository\LedgerAccountRepository::class);

        self::assertNotNull($storedEntry);
        self::assertCount(2, $storedEntry->getLines());
        self::assertSame('FY2026', $storedEntry->getPeriod()->getName());
        self::assertTrue($accountRepository->hasJournalLines((int) $bank->getId()));
        self::assertFalse($accountRepository->hasJournalLines(999999));
        self::assertSame('VAT21', $this->entityManager->getRepository(TaxCode::class)->find($taxCode->getId())?->getCode());
        self::assertSame(5000, self::getContainer()->get(\App\Repository\PayablePaymentRepository::class)->sumMinorForBill((int) $storedBill?->getId()));
    }

    public function test_purchase_receipt_and_supplier_return_drive_warehouse_stock_ledger(): void
    {
        $product = new Product('FILTER-01', 'Air filter', 'EUR', 2500);
        $supplier = new Supplier('SUP-01', 'Parts supplier', null, null, null);
        $warehouse = new Warehouse('WH-A', 'Main warehouse', null);
        $order = new PurchaseOrder($supplier, 'EUR', [[
            'product_id' => 1,
            'sku' => 'FILTER-01',
            'description' => 'Air filter',
            'quantity' => 10,
            'unit_cost_minor' => 1200,
            'line_total_minor' => 12000,
        ]], 12000, new DateTimeImmutable('2026-10-01'));
        $receipt = new GoodsReceipt($order, $warehouse, [['product_id' => 1, 'description' => 'Air filter', 'quantity' => 5]], new DateTimeImmutable('2026-10-03'), 'GR-01');
        $return = new InventoryReturn('supplier', $warehouse, 'purchase_order', 1, [['product_id' => 1, 'description' => 'Air filter', 'quantity' => 1]], new DateTimeImmutable('2026-10-04'), 'RTN-01');
        $inbound = new StockMovement($product, $warehouse, 'goods_receipt', 5, 'goods_receipt', 1, new DateTimeImmutable('2026-10-03'), 'Goods received');
        $outbound = new StockMovement($product, $warehouse, 'supplier_return', -1, 'inventory_return', 1, new DateTimeImmutable('2026-10-04'), 'Returned to supplier');
        foreach ([$product, $supplier, $warehouse, $order, $receipt, $return, $inbound, $outbound] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        $movementRepository = self::getContainer()->get(\App\Repository\StockMovementRepository::class);
        self::assertSame(4, $movementRepository->onHand((int) $product->getId(), (int) $warehouse->getId()));
        self::assertTrue($movementRepository->hasSource('goods_receipt', (int) $receipt->getId()));
        self::assertCount(1, self::getContainer()->get(\App\Repository\GoodsReceiptRepository::class)->findForPurchaseOrder((int) $order->getId()));
    }

    public function test_stock_ledger_rejects_movements_that_would_create_negative_stock(): void
    {
        $product = new Product('BOLT-01', 'Steel bolt', 'EUR', 10);
        $warehouse = new Warehouse('WH-1', 'Main warehouse', null);
        $this->entityManager->persist($product);
        $this->entityManager->persist($warehouse);
        $this->entityManager->flush();

        $ledger = new StockLedger(self::getContainer()->get(\App\Repository\StockMovementRepository::class), $this->entityManager);
        $ledger->record($product, $warehouse, 'adjustment_in', 6, 'adjustment', null, new DateTimeImmutable('2026-10-05'), 'Opening count');
        $this->entityManager->flush();
        $ledger->record($product, $warehouse, 'sales_shipment', -4, 'sales_shipment', 12, new DateTimeImmutable('2026-10-05'), 'Dispatch');
        $this->entityManager->flush();

        self::assertSame(2, $ledger->onHand($product, $warehouse));
        $this->expectException(\DomainException::class);
        $ledger->record($product, $warehouse, 'supplier_return', -3, 'inventory_return', 7, new DateTimeImmutable('2026-10-05'), 'Return');
    }

    public function test_project_time_expense_approval_and_document_metadata_persist(): void
    {
        $now = new DateTimeImmutable('2026-10-05');
        $user = (new User())->setEmail('manager@example.com')->setPasswordHash('not-a-real-password-hash');
        $client = (new Client())->setTitle('Ops client')->setNotes('Operations test')->setCreatedAt($now)->setUpdatedAt($now);
        $project = (new Project())->setTitle('Operations')->setDescription('Delivery work')->setClient($client)->setCreatedAt($now)->setUpdatedAt($now);
        $task = (new Task())->setTitle('Implementation')->setDescription('Build and test')->setProject($project)->setSchedule($now, $now->modify('+2 hours'))->setCreatedAt($now)->setUpdatedAt($now);
        $timeEntry = new TimeEntry($project, $task, $user, $now, 90, true, 'Implementation and test pass');
        $expense = new Expense($project, $user, 'Cloud service', 4500, 'EUR', $now);
        $expense->decide('approved');
        $approval = new ExpenseApproval($expense, $user, 'approved', 'Within project budget');
        $document = new ProjectDocument($project, $user, 'specification.pdf', str_repeat('a', 48), 'application/pdf', 1024);
        foreach ([$user, $client, $project, $task, $timeEntry, $expense, $approval, $document] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
        $this->entityManager->clear();

        $storedExpense = $this->entityManager->getRepository(Expense::class)->find($expense->getId());

        self::assertSame(90, $this->entityManager->getRepository(TimeEntry::class)->find($timeEntry->getId())?->getDurationMinutes());
        self::assertSame('approved', $storedExpense?->getStatus());
        self::assertCount(1, self::getContainer()->get(\App\Repository\ExpenseApprovalRepository::class)->findForExpense((int) $expense->getId()));
        self::assertSame('specification.pdf', $this->entityManager->getRepository(ProjectDocument::class)->find($document->getId())?->getOriginalName());
    }
}

