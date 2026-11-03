<?php

namespace App\Controller;

use App\Dto\GoodsReceiptInput;
use App\Dto\InventoryReturnInput;
use App\Dto\PurchaseOrderInput;
use App\Dto\ShipmentDispatchInput;
use App\Dto\StockAdjustmentInput;
use App\Dto\SupplierInput;
use App\Dto\WarehouseInput;
use App\Entity\GoodsReceipt;
use App\Entity\InventoryReturn;
use App\Entity\Product;
use App\Entity\PurchaseOrder;
use App\Entity\SalesDocument;
use App\Entity\Shipment;
use App\Entity\StockMovement;
use App\Entity\Supplier;
use App\Entity\Warehouse;
use App\Repository\GoodsReceiptRepository;
use App\Repository\InventoryReturnRepository;
use App\Repository\ProductRepository;
use App\Repository\PurchaseOrderRepository;
use App\Repository\SalesDocumentRepository;
use App\Repository\ShipmentRepository;
use App\Repository\StockMovementRepository;
use App\Repository\SupplierRepository;
use App\Repository\WarehouseRepository;
use App\Service\FinanceCatalog;
use App\Service\InvoiceMoney;
use App\Service\StockLedger;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/inventory', name: 'api_inventory_')]
#[IsGranted('ROLE_USER')]
final class InventoryController extends AbstractController
{
    public function __construct(
        private readonly WarehouseRepository $warehouseRepository,
        private readonly SupplierRepository $supplierRepository,
        private readonly PurchaseOrderRepository $purchaseOrderRepository,
        private readonly GoodsReceiptRepository $goodsReceiptRepository,
        private readonly InventoryReturnRepository $returnRepository,
        private readonly StockMovementRepository $movementRepository,
        private readonly ProductRepository $productRepository,
        private readonly SalesDocumentRepository $salesDocumentRepository,
        private readonly ShipmentRepository $shipmentRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly StockLedger $stockLedger,
        private readonly InvoiceMoney $money,
        private readonly FinanceCatalog $financeCatalog,
    ) {
    }

    #[Route('/warehouses', name: 'warehouses_list', methods: ['GET'])]
    public function warehouses(): JsonResponse
    {
        return $this->json(array_map(fn (Warehouse $warehouse): array => $this->serializeWarehouse($warehouse), $this->warehouseRepository->findBy([], ['code' => 'ASC'])));
    }

    #[Route('/warehouses', name: 'warehouses_create', methods: ['POST'])]
    public function createWarehouse(#[MapRequestPayload] WarehouseInput $input): JsonResponse
    {
        $data = $this->warehouseData($input);
        if (isset($data['error'])) return $this->json(['error' => $data['error']], JsonResponse::HTTP_BAD_REQUEST);
        if ($this->warehouseRepository->findOneBy(['code' => $data['code']]) !== null) return $this->json(['error' => 'Warehouse code is already in use.'], JsonResponse::HTTP_CONFLICT);
        $warehouse = new Warehouse($data['code'], $data['name'], $data['address']);
        $warehouse->update($data['code'], $data['name'], $data['address'], $data['active']);
        $this->entityManager->persist($warehouse);
        $this->entityManager->flush();
        return $this->json($this->serializeWarehouse($warehouse), JsonResponse::HTTP_CREATED);
    }

    #[Route('/warehouses/{id<\d+>}', name: 'warehouses_update', methods: ['PUT', 'PATCH'])]
    public function updateWarehouse(int $id, #[MapRequestPayload] WarehouseInput $input): JsonResponse
    {
        $warehouse = $this->warehouseRepository->find($id);
        if ($warehouse === null) return $this->json(['error' => 'Warehouse not found.'], JsonResponse::HTTP_NOT_FOUND);
        $data = $this->warehouseData($input);
        if (isset($data['error'])) return $this->json(['error' => $data['error']], JsonResponse::HTTP_BAD_REQUEST);
        $duplicate = $this->warehouseRepository->findOneBy(['code' => $data['code']]);
        if ($duplicate !== null && $duplicate->getId() !== $id) return $this->json(['error' => 'Warehouse code is already in use.'], JsonResponse::HTTP_CONFLICT);
        $warehouse->update($data['code'], $data['name'], $data['address'], $data['active']);
        $this->entityManager->flush();
        return $this->json($this->serializeWarehouse($warehouse));
    }

    #[Route('/suppliers', name: 'suppliers_list', methods: ['GET'])]
    public function suppliers(): JsonResponse
    {
        return $this->json(array_map(fn (Supplier $supplier): array => $this->serializeSupplier($supplier), $this->supplierRepository->findBy([], ['name' => 'ASC'])));
    }

    #[Route('/suppliers', name: 'suppliers_create', methods: ['POST'])]
    public function createSupplier(#[MapRequestPayload] SupplierInput $input): JsonResponse
    {
        $data = $this->supplierData($input);
        if (isset($data['error'])) return $this->json(['error' => $data['error']], JsonResponse::HTTP_BAD_REQUEST);
        if ($this->supplierRepository->findOneBy(['code' => $data['code']]) !== null) return $this->json(['error' => 'Supplier code is already in use.'], JsonResponse::HTTP_CONFLICT);
        $supplier = new Supplier($data['code'], $data['name'], $data['email'], $data['phone'], $data['address']);
        $supplier->update($data['code'], $data['name'], $data['email'], $data['phone'], $data['address'], $data['active']);
        $this->entityManager->persist($supplier);
        $this->entityManager->flush();
        return $this->json($this->serializeSupplier($supplier), JsonResponse::HTTP_CREATED);
    }

    #[Route('/suppliers/{id<\d+>}', name: 'suppliers_update', methods: ['PUT', 'PATCH'])]
    public function updateSupplier(int $id, #[MapRequestPayload] SupplierInput $input): JsonResponse
    {
        $supplier = $this->supplierRepository->find($id);
        if ($supplier === null) return $this->json(['error' => 'Supplier not found.'], JsonResponse::HTTP_NOT_FOUND);
        $data = $this->supplierData($input);
        if (isset($data['error'])) return $this->json(['error' => $data['error']], JsonResponse::HTTP_BAD_REQUEST);
        $duplicate = $this->supplierRepository->findOneBy(['code' => $data['code']]);
        if ($duplicate !== null && $duplicate->getId() !== $id) return $this->json(['error' => 'Supplier code is already in use.'], JsonResponse::HTTP_CONFLICT);
        $supplier->update($data['code'], $data['name'], $data['email'], $data['phone'], $data['address'], $data['active']);
        $this->entityManager->flush();
        return $this->json($this->serializeSupplier($supplier));
    }

    #[Route('/purchase-orders', name: 'purchase_orders_list', methods: ['GET'])]
    public function purchaseOrders(): JsonResponse
    {
        return $this->json(array_map(fn (PurchaseOrder $order): array => $this->serializePurchaseOrder($order), $this->purchaseOrderRepository->findRecent()));
    }

    #[Route('/purchase-orders', name: 'purchase_orders_create', methods: ['POST'])]
    public function createPurchaseOrder(#[MapRequestPayload] PurchaseOrderInput $input): JsonResponse
    {
        $data = $this->purchaseOrderData($input);
        if (isset($data['error'])) return $this->json(['error' => $data['error']], JsonResponse::HTTP_BAD_REQUEST);
        $supplier = $this->supplierRepository->find($data['supplier_id']);
        if ($supplier === null || !$supplier->isActive()) return $this->json(['error' => 'An active supplier is required.'], JsonResponse::HTTP_NOT_FOUND);

        $order = new PurchaseOrder($supplier, $data['currency'], $data['items'], $data['total_minor'], $data['ordered_at']);
        $this->entityManager->persist($order);
        $this->entityManager->flush();
        $order->setOrderNumber(sprintf('PO-%s-%06d', $data['ordered_at']->format('Y'), $order->getId()));
        $this->entityManager->flush();
        return $this->json($this->serializePurchaseOrder($order), JsonResponse::HTTP_CREATED);
    }

    #[Route('/purchase-orders/{id<\d+>}/receipts', name: 'goods_receipt_create', methods: ['POST'])]
    public function receiveGoods(int $id, #[MapRequestPayload] GoodsReceiptInput $input): JsonResponse
    {
        $order = $this->purchaseOrderRepository->find($id);
        if ($order === null) return $this->json(['error' => 'Purchase order not found.'], JsonResponse::HTTP_NOT_FOUND);
        if (!in_array($order->getStatus(), ['open', 'partially_received'], true)) return $this->json(['error' => 'This purchase order cannot receive goods.'], JsonResponse::HTTP_CONFLICT);
        $warehouse = $input->warehouse_id !== null ? $this->warehouseRepository->find($input->warehouse_id) : null;
        if ($warehouse === null || !$warehouse->isActive()) return $this->json(['error' => 'An active warehouse is required.'], JsonResponse::HTTP_BAD_REQUEST);
        try {
            $receivedAt = $input->received_at !== null ? $this->parseDate($input->received_at) : new \DateTimeImmutable('today');
        } catch (\InvalidArgumentException) {
            return $this->json(['error' => 'Receipt date must use YYYY-MM-DD.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $ordered = $this->quantitiesByProduct($order->getItems());
        $received = $this->receivedForOrder($id);
        $lines = $this->validateQuantityLines($input->items, $ordered, $received);
        if (isset($lines['error'])) return $this->json(['error' => $lines['error']], JsonResponse::HTTP_CONFLICT);
        if ($lines['items'] === []) return $this->json(['error' => 'At least one receipt line is required.'], JsonResponse::HTTP_BAD_REQUEST);

        try {
            $receipt = $this->entityManager->wrapInTransaction(function () use ($order, $warehouse, $receivedAt, $input, $lines, $ordered, $received): GoodsReceipt {
                $receipt = new GoodsReceipt($order, $warehouse, $lines['items'], $receivedAt, $input->reference !== null ? trim($input->reference) : null);
                $this->entityManager->persist($receipt);
                $this->entityManager->flush();
                foreach ($lines['items'] as $line) {
                    $product = $this->productRepository->find($line['product_id']);
                    $this->stockLedger->record($product, $warehouse, 'goods_receipt', $line['quantity'], 'goods_receipt', $receipt->getId(), $receivedAt, 'Receipt for '.$order->getOrderNumber());
                    $received[$line['product_id']] = ($received[$line['product_id']] ?? 0) + $line['quantity'];
                }
                $complete = true;
                foreach ($ordered as $productId => $quantity) {
                    if (($received[$productId] ?? 0) < $quantity) { $complete = false; break; }
                }
                $order->setStatus($complete ? 'received' : 'partially_received');
                $this->entityManager->flush();
                return $receipt;
            });
        } catch (\DomainException|\OverflowException|UniqueConstraintViolationException $exception) {
            return $this->json(['error' => $exception->getMessage()], JsonResponse::HTTP_CONFLICT);
        }
        return $this->json($this->serializeReceipt($receipt), JsonResponse::HTTP_CREATED);
    }

    #[Route('/stock', name: 'stock_list', methods: ['GET'])]
    public function stock(): JsonResponse
    {
        $warehouses = [];
        foreach ($this->warehouseRepository->findBy([], ['code' => 'ASC']) as $warehouse) $warehouses[$warehouse->getId()] = $warehouse;
        $products = [];
        foreach ($this->productRepository->findBy([], ['sku' => 'ASC']) as $product) $products[$product->getId()] = $product;
        $rows = [];
        foreach ($this->movementRepository->findBalances() as $balance) {
            $product = $products[$balance['product_id']] ?? null;
            $warehouse = $warehouses[$balance['warehouse_id']] ?? null;
            if ($product === null || $warehouse === null) continue;
            $rows[] = [
                'product_id' => $product->getId(), 'sku' => $product->getSku(), 'product_name' => $product->getName(),
                'warehouse_id' => $warehouse->getId(), 'warehouse_code' => $warehouse->getCode(), 'warehouse_name' => $warehouse->getName(),
                'quantity_on_hand' => (int) $balance['quantity'],
            ];
        }
        return $this->json($rows);
    }

    #[Route('/movements', name: 'movements_list', methods: ['GET'])]
    public function movements(): JsonResponse
    {
        return $this->json(array_map(fn (StockMovement $movement): array => $this->serializeMovement($movement), $this->movementRepository->findRecent()));
    }

    #[Route('/adjustments', name: 'adjustment_create', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function adjustStock(#[MapRequestPayload] StockAdjustmentInput $input): JsonResponse
    {
        $product = $input->product_id !== null ? $this->productRepository->find($input->product_id) : null;
        $warehouse = $input->warehouse_id !== null ? $this->warehouseRepository->find($input->warehouse_id) : null;
        if ($product === null || $warehouse === null || !$warehouse->isActive() || $input->quantity_delta === null || $input->quantity_delta === 0 || $input->reason === null || trim($input->reason) === '') {
            return $this->json(['error' => 'Product, active warehouse, non-zero quantity delta, and reason are required.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        try {
            $movedAt = $input->moved_at !== null ? $this->parseDate($input->moved_at) : new \DateTimeImmutable('today');
        } catch (\InvalidArgumentException) {
            return $this->json(['error' => 'Movement date must use YYYY-MM-DD.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        try {
            $movement = $this->entityManager->wrapInTransaction(function () use ($product, $warehouse, $input, $movedAt): StockMovement {
                $type = $input->quantity_delta > 0 ? 'adjustment_in' : 'adjustment_out';
                $movement = $this->stockLedger->record($product, $warehouse, $type, $input->quantity_delta, 'adjustment', null, $movedAt, trim($input->reason));
                $this->entityManager->flush();
                return $movement;
            });
        } catch (\DomainException|\OverflowException $exception) {
            return $this->json(['error' => $exception->getMessage()], JsonResponse::HTTP_CONFLICT);
        }
        return $this->json($this->serializeMovement($movement), JsonResponse::HTTP_CREATED);
    }

    #[Route('/sales-shipments/pending', name: 'pending_sales_shipments', methods: ['GET'])]
    public function pendingSalesShipments(): JsonResponse
    {
        $pending = [];
        foreach ($this->salesDocumentRepository->findByType('sales_order') as $order) {
            foreach ($this->shipmentRepository->findForOrder((int) $order->getId()) as $shipment) {
                if (!$this->movementRepository->hasSource('sales_shipment', (int) $shipment->getId())) {
                    $pending[] = ['shipment_id' => $shipment->getId(), 'sales_order_id' => $order->getId(), 'order_number' => $order->getDocumentNumber(), 'client_name' => $order->getClient()->getTitle(), 'shipped_at' => $shipment->getShippedAt()->format('Y-m-d'), 'reference' => $shipment->getReference(), 'items' => $shipment->getItems()];
                }
            }
        }
        return $this->json($pending);
    }

    #[Route('/sales-shipments/dispatched', name: 'dispatched_sales_shipments', methods: ['GET'])]
    public function dispatchedSalesShipments(): JsonResponse
    {
        $dispatched = [];
        foreach ($this->salesDocumentRepository->findByType('sales_order') as $order) {
            foreach ($this->shipmentRepository->findForOrder((int) $order->getId()) as $shipment) {
                if ($this->movementRepository->hasSource('sales_shipment', (int) $shipment->getId())) {
                    $dispatched[] = ['shipment_id' => $shipment->getId(), 'sales_order_id' => $order->getId(), 'order_number' => $order->getDocumentNumber(), 'client_name' => $order->getClient()->getTitle(), 'shipped_at' => $shipment->getShippedAt()->format('Y-m-d'), 'reference' => $shipment->getReference(), 'items' => $shipment->getItems()];
                }
            }
        }
        return $this->json($dispatched);
    }

    #[Route('/sales-shipments/{id<\d+>}/dispatch', name: 'sales_shipment_dispatch', methods: ['POST'])]
    public function dispatchSalesShipment(int $id, #[MapRequestPayload] ShipmentDispatchInput $input): JsonResponse
    {
        $shipment = $this->shipmentRepository->find($id);
        if ($shipment === null) return $this->json(['error' => 'Sales shipment not found.'], JsonResponse::HTTP_NOT_FOUND);
        if ($this->movementRepository->hasSource('sales_shipment', $id)) return $this->json(['error' => 'This shipment has already been dispatched from inventory.'], JsonResponse::HTTP_CONFLICT);
        $warehouse = $input->warehouse_id !== null ? $this->warehouseRepository->find($input->warehouse_id) : null;
        if ($warehouse === null || !$warehouse->isActive()) return $this->json(['error' => 'An active warehouse is required.'], JsonResponse::HTTP_BAD_REQUEST);
        try {
            $movedAt = $input->moved_at !== null ? $this->parseDate($input->moved_at) : $shipment->getShippedAt();
        } catch (\InvalidArgumentException) {
            return $this->json(['error' => 'Dispatch date must use YYYY-MM-DD.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        $products = [];
        foreach ($shipment->getItems() as $line) {
            $product = $this->productRepository->find($line['product_id']);
            if ($product === null || !is_int($line['quantity']) || $line['quantity'] < 1 || $this->stockLedger->onHand($product, $warehouse) < $line['quantity']) {
                return $this->json(['error' => 'Warehouse stock is insufficient for this shipment.'], JsonResponse::HTTP_CONFLICT);
            }
            $products[] = [$product, $line];
        }
        try {
            $this->entityManager->wrapInTransaction(function () use ($products, $warehouse, $movedAt, $shipment): void {
                foreach ($products as [$product, $line]) {
                    $this->stockLedger->record($product, $warehouse, 'sales_shipment', -$line['quantity'], 'sales_shipment', $shipment->getId(), $movedAt, $line['description']);
                }
                $this->entityManager->flush();
            });
        } catch (\DomainException|\OverflowException|UniqueConstraintViolationException $exception) {
            return $this->json(['error' => $exception->getMessage()], JsonResponse::HTTP_CONFLICT);
        }
        return $this->json(['shipment_id' => $id, 'warehouse_id' => $warehouse->getId(), 'dispatched_at' => $movedAt->format('Y-m-d'), 'status' => 'dispatched']);
    }

    #[Route('/returns', name: 'returns_list', methods: ['GET'])]
    public function returns(): JsonResponse
    {
        $items = $this->returnRepository->findBy([], ['returnedAt' => 'DESC', 'id' => 'DESC']);
        return $this->json(array_map(fn (InventoryReturn $return): array => $this->serializeReturn($return), $items));
    }

    #[Route('/returns', name: 'returns_create', methods: ['POST'])]
    public function createReturn(#[MapRequestPayload] InventoryReturnInput $input): JsonResponse
    {
        if ($input->return_type === null || $input->source_type === null || $input->source_id === null || $input->warehouse_id === null || $input->items === []) {
            return $this->json(['error' => 'Return type, source, warehouse, date, and at least one item are required.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        if (($input->return_type === 'customer' && $input->source_type !== 'sales_shipment') || ($input->return_type === 'supplier' && $input->source_type !== 'purchase_order')) {
            return $this->json(['error' => 'Customer returns must reference a sales shipment; supplier returns must reference a purchase order.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        $warehouse = $this->warehouseRepository->find($input->warehouse_id);
        if ($warehouse === null || !$warehouse->isActive()) return $this->json(['error' => 'An active warehouse is required.'], JsonResponse::HTTP_BAD_REQUEST);
        try {
            $returnedAt = $input->returned_at !== null ? $this->parseDate($input->returned_at) : new \DateTimeImmutable('today');
        } catch (\InvalidArgumentException) {
            return $this->json(['error' => 'Return date must use YYYY-MM-DD.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        if ($input->return_type === 'customer') {
            $source = $this->shipmentRepository->find($input->source_id);
            if ($source === null || !$this->movementRepository->hasSource('sales_shipment', $input->source_id)) return $this->json(['error' => 'A dispatched sales shipment is required for a customer return.'], JsonResponse::HTTP_CONFLICT);
            $sourceLines = $source->getItems();
        } else {
            $source = $this->purchaseOrderRepository->find($input->source_id);
            if ($source === null) return $this->json(['error' => 'Purchase order not found.'], JsonResponse::HTTP_NOT_FOUND);
            $sourceLines = $this->receivedForOrder($input->source_id, true);
        }
        $available = $this->returnableQuantities($input->return_type, $input->source_type, $input->source_id, $sourceLines);
        $validatedLines = $this->validateQuantityLines($input->items, $available, []);
        if (isset($validatedLines['error'])) return $this->json(['error' => $validatedLines['error']], JsonResponse::HTTP_CONFLICT);
        if ($validatedLines['items'] === []) return $this->json(['error' => 'At least one return line is required.'], JsonResponse::HTTP_BAD_REQUEST);
        if ($input->return_type === 'supplier') {
            foreach ($validatedLines['items'] as $line) {
                $product = $this->productRepository->find($line['product_id']);
                if ($this->stockLedger->onHand($product, $warehouse) < $line['quantity']) return $this->json(['error' => 'Warehouse stock is insufficient for this supplier return.'], JsonResponse::HTTP_CONFLICT);
            }
        }

        try {
            $return = $this->entityManager->wrapInTransaction(function () use ($input, $warehouse, $returnedAt, $validatedLines): InventoryReturn {
                $return = new InventoryReturn($input->return_type, $warehouse, $input->source_type, $input->source_id, $validatedLines['items'], $returnedAt, $input->reference !== null ? trim($input->reference) : null);
                $this->entityManager->persist($return);
                $this->entityManager->flush();
                foreach ($validatedLines['items'] as $line) {
                    $product = $this->productRepository->find($line['product_id']);
                    $delta = $input->return_type === 'customer' ? $line['quantity'] : -$line['quantity'];
                    $type = $input->return_type === 'customer' ? 'customer_return' : 'supplier_return';
                    $this->stockLedger->record($product, $warehouse, $type, $delta, 'inventory_return', $return->getId(), $returnedAt, $line['description']);
                }
                $this->entityManager->flush();
                return $return;
            });
        } catch (\DomainException|\OverflowException $exception) {
            return $this->json(['error' => $exception->getMessage()], JsonResponse::HTTP_CONFLICT);
        }
        return $this->json($this->serializeReturn($return), JsonResponse::HTTP_CREATED);
    }

    private function warehouseData(WarehouseInput $input): array
    {
        $code = strtoupper(trim((string) $input->code));
        $name = trim((string) $input->name);
        if ($code === '' || $name === '' || preg_match('/^[A-Z0-9_-]{1,32}$/D', $code) !== 1) return ['error' => 'Warehouse code and name are required.'];
        return ['code' => $code, 'name' => $name, 'address' => $input->address !== null ? trim($input->address) : null, 'active' => $input->active];
    }

    private function supplierData(SupplierInput $input): array
    {
        $code = strtoupper(trim((string) $input->code));
        $name = trim((string) $input->name);
        if ($code === '' || $name === '' || preg_match('/^[A-Z0-9_-]{1,32}$/D', $code) !== 1) return ['error' => 'Supplier code and name are required.'];
        return ['code' => $code, 'name' => $name, 'email' => $input->email !== null ? trim($input->email) : null, 'phone' => $input->phone !== null ? trim($input->phone) : null, 'address' => $input->address !== null ? trim($input->address) : null, 'active' => $input->active];
    }

    private function purchaseOrderData(PurchaseOrderInput $input): array
    {
        if ($input->supplier_id === null || $input->currency === null || !$this->financeCatalog->supportsCurrency($input->currency) || $input->items === []) return ['error' => 'Supplier, supported currency, and at least one product line are required.'];
        try { $orderedAt = $input->ordered_at !== null ? $this->parseDate($input->ordered_at) : new \DateTimeImmutable('today'); }
        catch (\InvalidArgumentException) { return ['error' => 'Order date must use YYYY-MM-DD.']; }
        $items = [];
        $seen = [];
        $total = 0;
        foreach ($input->items as $line) {
            $productId = $line['product_id'] ?? null;
            $quantity = $line['quantity'] ?? null;
            $unitCost = $line['unit_cost'] ?? null;
            if (!is_int($productId) || !is_int($quantity) || $quantity < 1 || !is_string($unitCost) || isset($seen[$productId])) return ['error' => 'Each line requires a unique product_id, positive integer quantity, and unit_cost amount.'];
            $product = $this->productRepository->find($productId);
            $unitCostMinor = $this->money->parseMinor($unitCost);
            if ($product === null || !$product->isActive() || $unitCostMinor === null) return ['error' => 'Each line must reference an active product and valid unit cost.'];
            if ($unitCostMinor > intdiv(PHP_INT_MAX, $quantity)) return ['error' => 'Purchase order line total is too large.'];
            $lineTotal = $unitCostMinor * $quantity;
            if ($lineTotal > PHP_INT_MAX - $total) return ['error' => 'Purchase order total is too large.'];
            $total += $lineTotal;
            $items[] = ['product_id' => $productId, 'sku' => $product->getSku(), 'description' => $product->getName(), 'quantity' => $quantity, 'unit_cost_minor' => $unitCostMinor, 'line_total_minor' => $lineTotal];
            $seen[$productId] = true;
        }
        if ($total < 1) return ['error' => 'Purchase order total must be greater than zero.'];
        return ['supplier_id' => $input->supplier_id, 'currency' => $input->currency, 'ordered_at' => $orderedAt, 'items' => $items, 'total_minor' => $total];
    }

    private function receivedForOrder(int $orderId, bool $asLines = false): array
    {
        $quantities = [];
        $descriptions = [];
        foreach ($this->goodsReceiptRepository->findForPurchaseOrder($orderId) as $receipt) {
            foreach ($receipt->getItems() as $line) {
                $quantities[$line['product_id']] = ($quantities[$line['product_id']] ?? 0) + $line['quantity'];
                $descriptions[$line['product_id']] = $line['description'];
            }
        }
        if (!$asLines) return $quantities;
        return array_map(static fn (int $productId, int $quantity): array => ['product_id' => $productId, 'quantity' => $quantity, 'description' => $descriptions[$productId] ?? ''], array_keys($quantities), array_values($quantities));
    }

    private function returnableQuantities(string $returnType, string $sourceType, int $sourceId, array $sourceLines): array
    {
        $available = $this->quantitiesByProduct($sourceLines);
        $alreadyReturned = [];
        foreach ($this->returnRepository->findForSource($returnType, $sourceType, $sourceId) as $return) {
            foreach ($return->getItems() as $line) $alreadyReturned[$line['product_id']] = ($alreadyReturned[$line['product_id']] ?? 0) + $line['quantity'];
        }
        foreach ($available as $productId => $quantity) $available[$productId] = max(0, $quantity - ($alreadyReturned[$productId] ?? 0));
        return $available;
    }

    private function quantitiesByProduct(array $items): array
    {
        $quantities = [];
        foreach ($items as $line) $quantities[$line['product_id']] = ($quantities[$line['product_id']] ?? 0) + $line['quantity'];
        return $quantities;
    }

    private function validateQuantityLines(array $requested, array $maximum, array $already): array
    {
        $items = [];
        $seen = [];
        foreach ($requested as $line) {
            $productId = $line['product_id'] ?? null;
            $quantity = $line['quantity'] ?? null;
            if (!is_int($productId) || !is_int($quantity) || $quantity < 1 || isset($seen[$productId]) || !isset($maximum[$productId])) return ['error' => 'Lines require unique product ids and positive integer quantities from the source document.'];
            if ($quantity > $maximum[$productId] - ($already[$productId] ?? 0)) return ['error' => 'Quantity exceeds the unreceived or returnable source quantity.'];
            $product = $this->productRepository->find($productId);
            if ($product === null) return ['error' => 'Product referenced by the source document no longer exists.'];
            $items[] = ['product_id' => $productId, 'description' => $product->getName(), 'quantity' => $quantity];
            $seen[$productId] = true;
        }
        return ['items' => $items];
    }

    private function parseDate(string $value): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) throw new \InvalidArgumentException('Invalid calendar date.');
        return $date;
    }

    private function serializeWarehouse(Warehouse $warehouse): array
    {
        return ['id' => $warehouse->getId(), 'code' => $warehouse->getCode(), 'name' => $warehouse->getName(), 'address' => $warehouse->getAddress(), 'active' => $warehouse->isActive()];
    }

    private function serializeSupplier(Supplier $supplier): array
    {
        return ['id' => $supplier->getId(), 'code' => $supplier->getCode(), 'name' => $supplier->getName(), 'email' => $supplier->getEmail(), 'phone' => $supplier->getPhone(), 'address' => $supplier->getAddress(), 'active' => $supplier->isActive()];
    }

    private function serializePurchaseOrder(PurchaseOrder $order): array
    {
        $received = $this->receivedForOrder((int) $order->getId());
        return ['id' => $order->getId(), 'order_number' => $order->getOrderNumber(), 'supplier_id' => $order->getSupplier()->getId(), 'supplier_code' => $order->getSupplier()->getCode(), 'supplier_name' => $order->getSupplier()->getName(), 'currency' => $order->getCurrency(), 'items' => array_map(fn (array $line): array => $line + ['unit_cost' => $this->money->formatMinor($line['unit_cost_minor']), 'line_total' => $this->money->formatMinor($line['line_total_minor']), 'received_quantity' => $received[$line['product_id']] ?? 0], $order->getItems()), 'total' => $this->money->formatMinor($order->getTotalMinor()), 'status' => $order->getStatus(), 'ordered_at' => $order->getOrderedAt()->format('Y-m-d')];
    }

    private function serializeReceipt(GoodsReceipt $receipt): array
    {
        return ['id' => $receipt->getId(), 'purchase_order_id' => $receipt->getPurchaseOrder()->getId(), 'order_number' => $receipt->getPurchaseOrder()->getOrderNumber(), 'warehouse_id' => $receipt->getWarehouse()->getId(), 'warehouse_code' => $receipt->getWarehouse()->getCode(), 'reference' => $receipt->getReference(), 'received_at' => $receipt->getReceivedAt()->format('Y-m-d'), 'items' => $receipt->getItems()];
    }

    private function serializeMovement(StockMovement $movement): array
    {
        return ['id' => $movement->getId(), 'product_id' => $movement->getProduct()->getId(), 'sku' => $movement->getProduct()->getSku(), 'product_name' => $movement->getProduct()->getName(), 'warehouse_id' => $movement->getWarehouse()->getId(), 'warehouse_code' => $movement->getWarehouse()->getCode(), 'movement_type' => $movement->getMovementType(), 'quantity_delta' => $movement->getQuantityDelta(), 'source_type' => $movement->getSourceType(), 'source_id' => $movement->getSourceId(), 'moved_at' => $movement->getMovedAt()->format('Y-m-d'), 'memo' => $movement->getMemo()];
    }

    private function serializeReturn(InventoryReturn $return): array
    {
        return ['id' => $return->getId(), 'return_type' => $return->getReturnType(), 'source_type' => $return->getSourceType(), 'source_id' => $return->getSourceId(), 'warehouse_id' => $return->getWarehouse()->getId(), 'warehouse_code' => $return->getWarehouse()->getCode(), 'reference' => $return->getReference(), 'returned_at' => $return->getReturnedAt()->format('Y-m-d'), 'items' => $return->getItems()];
    }
}