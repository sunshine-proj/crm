<?php

namespace App\Controller;

use App\Dto\InvoiceFromOrderInput;
use App\Dto\PaymentInput;
use App\Dto\ProductInput;
use App\Dto\SalesQuoteInput;
use App\Dto\SalesQuoteStatusInput;
use App\Dto\ShipmentInput;
use App\Entity\Invoice;
use App\Entity\Payment;
use App\Entity\Product;
use App\Entity\SalesDocument;
use App\Entity\Shipment;
use App\Entity\User;
use App\Repository\ClientRepository;
use App\Repository\InvoiceRepository;
use App\Repository\PaymentRepository;
use App\Repository\ProductRepository;
use App\Repository\SalesDocumentRepository;
use App\Repository\ShipmentRepository;
use App\Service\InvoiceMoney;
use App\Service\FinancePostingService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\LockMode;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api', name: 'api_sales_')]
#[IsGranted('ROLE_USER')]
final class SalesController extends AbstractController
{
    public function __construct(
        private readonly ProductRepository $productRepository,
        private readonly SalesDocumentRepository $documentRepository,
        private readonly ShipmentRepository $shipmentRepository,
        private readonly ClientRepository $clientRepository,
        private readonly InvoiceRepository $invoiceRepository,
        private readonly PaymentRepository $paymentRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly InvoiceMoney $money,
        private readonly FinancePostingService $postingService,
    ) {
    }

    #[Route('/products', name: 'products_list', methods: ['GET'])]
    public function products(): JsonResponse
    {
        $products = $this->productRepository->findBy([], ['name' => 'ASC']);
        return $this->json(array_map(fn (Product $product): array => $this->serializeProduct($product), $products));
    }

    #[Route('/products', name: 'products_create', methods: ['POST'])]
    public function createProduct(#[MapRequestPayload] ProductInput $input): JsonResponse
    {
        $data = $this->validatedProduct($input);
        if (isset($data['error'])) {
            return $this->json(['error' => $data['error']], JsonResponse::HTTP_BAD_REQUEST);
        }
        if ($this->productRepository->findOneBy(['sku' => $data['sku']]) !== null) {
            return $this->json(['error' => 'SKU is already in use.'], JsonResponse::HTTP_CONFLICT);
        }

        $product = new Product($data['sku'], $data['name'], $data['currency'], $data['unit_price_minor']);
        $product->update($data['sku'], $data['name'], $data['description'], $data['currency'], $data['unit_price_minor'], $data['active']);
        $this->entityManager->persist($product);
        $this->entityManager->flush();

        return $this->json($this->serializeProduct($product), JsonResponse::HTTP_CREATED);
    }

    #[Route('/products/{id<\d+>}', name: 'products_update', methods: ['PUT', 'PATCH'])]
    public function updateProduct(int $id, #[MapRequestPayload] ProductInput $input): JsonResponse
    {
        $product = $this->productRepository->find($id);
        if ($product === null) {
            return $this->json(['error' => 'Product not found.'], JsonResponse::HTTP_NOT_FOUND);
        }
        $data = $this->validatedProduct($input);
        if (isset($data['error'])) {
            return $this->json(['error' => $data['error']], JsonResponse::HTTP_BAD_REQUEST);
        }
        $duplicate = $this->productRepository->findOneBy(['sku' => $data['sku']]);
        if ($duplicate !== null && $duplicate->getId() !== $id) {
            return $this->json(['error' => 'SKU is already in use.'], JsonResponse::HTTP_CONFLICT);
        }

        $product->update($data['sku'], $data['name'], $data['description'], $data['currency'], $data['unit_price_minor'], $data['active']);
        $this->entityManager->flush();

        return $this->json($this->serializeProduct($product));
    }

    #[Route('/quotes', name: 'quotes_list', methods: ['GET'])]
    public function quotes(): JsonResponse
    {
        return $this->json(array_map(fn (SalesDocument $quote): array => $this->serializeDocument($quote), $this->documentRepository->findByType('quote')));
    }

    #[Route('/quotes', name: 'quotes_create', methods: ['POST'])]
    public function createQuote(#[MapRequestPayload] SalesQuoteInput $input): JsonResponse
    {
        $data = $this->validatedDocument($input);
        if (isset($data['error'])) {
            return $this->json(['error' => $data['error']], JsonResponse::HTTP_BAD_REQUEST);
        }
        $client = $this->clientRepository->find($data['client_id']);
        if ($client === null) {
            return $this->json(['error' => 'Client not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        $quote = new SalesDocument('quote', $client, $data['currency'], $data['items'], $data['total_minor'], $data['valid_until']);
        $this->entityManager->persist($quote);
        $this->entityManager->flush();
        $quote->setDocumentNumber(sprintf('QT-%s-%06d', (new \DateTimeImmutable())->format('Y'), $quote->getId()));
        $this->entityManager->flush();

        return $this->json($this->serializeDocument($quote), JsonResponse::HTTP_CREATED);
    }

    #[Route('/quotes/{id<\d+>}/status', name: 'quotes_status', methods: ['PATCH'])]
    public function updateQuoteStatus(int $id, #[MapRequestPayload] SalesQuoteStatusInput $input): JsonResponse
    {
        $quote = $this->documentRepository->find($id);
        if ($quote === null || $quote->getType() !== 'quote') {
            return $this->json(['error' => 'Quote not found.'], JsonResponse::HTTP_NOT_FOUND);
        }
        if ($input->status === null || !in_array([$quote->getStatus(), $input->status], [['draft', 'sent'], ['sent', 'accepted'], ['sent', 'rejected']], true)) {
            return $this->json(['error' => 'Quote status transition is not allowed.'], JsonResponse::HTTP_CONFLICT);
        }
        if ($input->status === 'accepted' && $quote->getValidUntil() !== null && $quote->getValidUntil() < new \DateTimeImmutable('today')) {
            return $this->json(['error' => 'An expired quote cannot be accepted.'], JsonResponse::HTTP_CONFLICT);
        }

        $quote->setStatus($input->status);
        $this->entityManager->flush();
        return $this->json($this->serializeDocument($quote));
    }

    #[Route('/quotes/{id<\d+>}/convert', name: 'quotes_convert', methods: ['POST'])]
    public function convertQuote(int $id): JsonResponse
    {
        $quote = $this->documentRepository->find($id);
        if ($quote === null || $quote->getType() !== 'quote') {
            return $this->json(['error' => 'Quote not found.'], JsonResponse::HTTP_NOT_FOUND);
        }
        $existing = $this->documentRepository->findOrderFromQuote($id);
        if ($existing !== null) {
            return $this->json($this->serializeDocument($existing));
        }
        if ($quote->getStatus() !== 'accepted') {
            return $this->json(['error' => 'Only accepted quotes can be converted to sales orders.'], JsonResponse::HTTP_CONFLICT);
        }

        $order = new SalesDocument('sales_order', $quote->getClient(), $quote->getCurrency(), $quote->getItems(), $quote->getTotalMinor(), null, $quote);
        $this->entityManager->persist($order);
        $this->entityManager->flush();
        $order->setDocumentNumber(sprintf('SO-%s-%06d', (new \DateTimeImmutable())->format('Y'), $order->getId()));
        $quote->setStatus('converted');
        $this->entityManager->flush();

        return $this->json($this->serializeDocument($order), JsonResponse::HTTP_CREATED);
    }

    #[Route('/sales-orders', name: 'orders_list', methods: ['GET'])]
    public function orders(): JsonResponse
    {
        return $this->json(array_map(fn (SalesDocument $order): array => $this->serializeOrder($order), $this->documentRepository->findByType('sales_order')));
    }

    #[Route('/sales-orders/{id<\d+>}/shipments', name: 'shipments_create', methods: ['POST'])]
    public function createShipment(int $id, #[MapRequestPayload] ShipmentInput $input): JsonResponse
    {
        $order = $this->documentRepository->find($id);
        if ($order === null || $order->getType() !== 'sales_order') {
            return $this->json(['error' => 'Sales order not found.'], JsonResponse::HTTP_NOT_FOUND);
        }
        if (!in_array($order->getStatus(), ['open', 'partially_shipped'], true)) {
            return $this->json(['error' => 'This sales order cannot accept shipments.'], JsonResponse::HTTP_CONFLICT);
        }
        try {
            $shippedAt = $input->shipped_at !== null ? $this->parseDate($input->shipped_at) : new \DateTimeImmutable('today');
        } catch (\InvalidArgumentException) {
            return $this->json(['error' => 'Shipment date must use YYYY-MM-DD.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        $ordered = [];
        foreach ($order->getItems() as $line) {
            $ordered[$line['product_id']] = ($ordered[$line['product_id']] ?? 0) + $line['quantity'];
        }
        $shipped = [];
        foreach ($this->shipmentRepository->findForOrder($id) as $shipment) {
            foreach ($shipment->getItems() as $line) {
                $shipped[$line['product_id']] = ($shipped[$line['product_id']] ?? 0) + $line['quantity'];
            }
        }
        $lines = [];
        $seen = [];
        foreach ($input->items as $line) {
            $productId = $line['product_id'] ?? null;
            $quantity = $line['quantity'] ?? null;
            if (!is_int($productId) || !is_int($quantity) || $quantity < 1 || isset($seen[$productId]) || !isset($ordered[$productId])) {
                return $this->json(['error' => 'Shipment lines require unique order product ids and positive integer quantities.'], JsonResponse::HTTP_BAD_REQUEST);
            }
            if ($quantity > $ordered[$productId] - ($shipped[$productId] ?? 0)) {
                return $this->json(['error' => 'Shipment quantity exceeds the unshipped order quantity.'], JsonResponse::HTTP_CONFLICT);
            }
            $description = '';
            foreach ($order->getItems() as $orderLine) {
                if ($orderLine['product_id'] === $productId) {
                    $description = $orderLine['description'];
                    break;
                }
            }
            $lines[] = ['product_id' => $productId, 'description' => $description, 'quantity' => $quantity];
            $shipped[$productId] = ($shipped[$productId] ?? 0) + $quantity;
            $seen[$productId] = true;
        }
        if ($lines === []) {
            return $this->json(['error' => 'At least one shipment line is required.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        $shipment = new Shipment($order, $lines, $shippedAt, $input->reference !== null ? trim($input->reference) : null);
        $this->entityManager->persist($shipment);
        $orderComplete = true;
        foreach ($ordered as $productId => $quantity) {
            if (($shipped[$productId] ?? 0) < $quantity) {
                $orderComplete = false;
                break;
            }
        }
        $order->setStatus($orderComplete ? 'shipped' : 'partially_shipped');
        $this->entityManager->flush();

        return $this->json($this->serializeShipment($shipment), JsonResponse::HTTP_CREATED);
    }

    #[Route('/sales-orders/{id<\d+>}/invoice', name: 'orders_invoice', methods: ['POST'])]
    public function invoiceOrder(int $id, #[MapRequestPayload] InvoiceFromOrderInput $input): JsonResponse
    {
        $order = $this->documentRepository->find($id);
        if ($order === null || $order->getType() !== 'sales_order') {
            return $this->json(['error' => 'Sales order not found.'], JsonResponse::HTTP_NOT_FOUND);
        }
        $existing = $this->invoiceRepository->findOneBy(['salesOrder' => $order]);
        if ($existing !== null) {
            return $this->json($this->serializeInvoice($existing));
        }
        if ($order->getStatus() !== 'shipped') {
            return $this->json(['error' => 'A sales order must be fully shipped before invoicing.'], JsonResponse::HTTP_CONFLICT);
        }
        try {
            if ($input->invoice_date === null) {
                throw new \InvalidArgumentException();
            }
            $invoiceDate = $this->parseDate($input->invoice_date);
            $dueDate = $input->due_date !== null ? $this->parseDate($input->due_date) : null;
        } catch (\InvalidArgumentException) {
            return $this->json(['error' => 'Invoice date and due date must use YYYY-MM-DD.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        if ($dueDate !== null && $dueDate < $invoiceDate) {
            return $this->json(['error' => 'Due date must be on or after the invoice date.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $invoiceItems = array_map(fn (array $line): array => [
            'task_id' => null,
            'description' => $line['description'],
            'amount' => $this->money->formatMinor($line['line_total_minor']),
        ], $order->getItems());
        $invoice = (new Invoice())->setDetails(
            $order->getClient(),
            null,
            null,
            (string) $order->getClient()->getTitle(),
            'Sales order '.$order->getDocumentNumber(),
            null,
            $invoiceDate,
            $dueDate,
            $order->getCurrency(),
            $invoiceItems,
            $order->getTotalMinor(),
        )->setSalesOrder($order);
        $this->entityManager->persist($invoice);
        $this->entityManager->flush();
        $invoice->setInvoiceNumber(sprintf('INV-%s-%06d', $invoiceDate->format('Y'), $invoice->getId()));
        $order->setStatus('invoiced');
        $this->entityManager->flush();

        return $this->json($this->serializeInvoice($invoice), JsonResponse::HTTP_CREATED);
    }

    #[Route('/invoices/{id<\d+>}/payments', name: 'payments_list', methods: ['GET'])]
    public function payments(int $id): JsonResponse
    {
        $invoice = $this->invoiceRepository->find($id);
        if ($invoice === null) {
            return $this->json(['error' => 'Invoice not found.'], JsonResponse::HTTP_NOT_FOUND);
        }
        return $this->json(array_map(fn (Payment $payment): array => $this->serializePayment($payment), $this->paymentRepository->findForInvoice($id)));
    }

    #[Route('/invoices/{id<\d+>}/payments', name: 'payments_create', methods: ['POST'])]
    public function createPayment(int $id, #[MapRequestPayload] PaymentInput $input): JsonResponse
    {
        $amountMinor = $input->amount !== null ? $this->money->parseMinor($input->amount) : null;
        if ($amountMinor === null || $amountMinor < 1) {
            return $this->json(['error' => 'Payment amount must be greater than zero and have at most two decimal places.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        try {
            if ($input->paid_at === null) {
                throw new \InvalidArgumentException();
            }
            $paidAt = $this->parseDate($input->paid_at);
        } catch (\InvalidArgumentException) {
            return $this->json(['error' => 'Payment date must use YYYY-MM-DD.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        try {
            $this->postingService->assertCanPost($paidAt, ['cash', 'accounts_receivable']);
        } catch (\DomainException $exception) {
            return $this->json(['error' => $exception->getMessage()], JsonResponse::HTTP_CONFLICT);
        }
        $payment = null;
        $balanceDue = null;
        $invoiceNotFound = false;
        try {
            $this->postingService->runAtomically(function () use ($id, $amountMinor, $paidAt, $input, &$payment, &$balanceDue, &$invoiceNotFound): void {
                $invoice = $this->invoiceRepository->find($id, LockMode::PESSIMISTIC_WRITE);
                if (!$invoice instanceof Invoice) {
                    $invoiceNotFound = true;
                    return;
                }
                if (!in_array($invoice->getStatus(), ['issued', 'partially_paid'], true)) {
                    throw new \DomainException('Only issued invoices can receive payments.');
                }

                $paidMinor = $this->paymentRepository->sumMinorForInvoice($id);
                $balanceDue = $invoice->getTotalMinor() - $paidMinor;
                if ($amountMinor > $balanceDue) {
                    throw new \DomainException('Payment exceeds the invoice balance due.');
                }

                $payment = new Payment($invoice, $amountMinor, $paidAt, $input->reference !== null ? trim($input->reference) : null);
                $invoice->setStatus($amountMinor === $balanceDue ? 'paid' : 'partially_paid');
                $this->entityManager->persist($payment);
                $this->entityManager->flush();
                $this->postingService->post('customer_receipt', (int) $payment->getId(), $paidAt, $invoice->getCurrency(), 'Customer receipt for '.$invoice->getInvoiceNumber(), [
                    ['role' => 'cash', 'debit_minor' => $amountMinor, 'credit_minor' => 0, 'memo' => 'Customer receipt'],
                    ['role' => 'accounts_receivable', 'debit_minor' => 0, 'credit_minor' => $amountMinor, 'memo' => 'Invoice receivable settlement'],
                ]);
                $balanceDue = $balanceDue - $amountMinor;
            });
        } catch (\DomainException $exception) {
            return $this->json(['error' => $exception->getMessage()], JsonResponse::HTTP_CONFLICT);
        }

        if ($invoiceNotFound || !($payment instanceof Payment) || $balanceDue === null) {
            return $this->json(['error' => 'Invoice not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        return $this->json(
            $this->serializePayment($payment) + [
                'balance_due' => $this->money->formatMinor($balanceDue),
            ],
            JsonResponse::HTTP_CREATED,
        );
    }

    private function validatedProduct(ProductInput $input): array
    {
        $sku = trim((string) $input->sku);
        $name = trim((string) $input->name);
        $price = $input->unit_price !== null ? $this->money->parseMinor($input->unit_price) : null;
        if ($sku === '' || $name === '' || strlen($sku) > 64 || strlen($name) > 255 || $input->currency === null || $price === null) {
            return ['error' => 'SKU, name, supported currency, and a non-negative unit price with at most two decimals are required.'];
        }
        return [
            'sku' => $sku,
            'name' => $name,
            'description' => $input->description !== null ? trim($input->description) : null,
            'currency' => $input->currency,
            'unit_price_minor' => $price,
            'active' => $input->active,
        ];
    }

    private function validatedDocument(SalesQuoteInput $input): array
    {
        if ($input->client_id === null || $input->currency === null || $input->items === []) {
            return ['error' => 'Client, currency, and at least one product line are required.'];
        }
        try {
            $validUntil = $input->valid_until !== null ? $this->parseDate($input->valid_until) : null;
        } catch (\InvalidArgumentException) {
            return ['error' => 'Quote expiry must use YYYY-MM-DD.'];
        }
        $items = [];
        $total = 0;
        $seen = [];
        foreach ($input->items as $line) {
            $productId = $line['product_id'] ?? null;
            $quantity = $line['quantity'] ?? null;
            if (!is_int($productId) || !is_int($quantity) || $quantity < 1 || isset($seen[$productId])) {
                return ['error' => 'Each quote line requires a unique product_id and positive integer quantity.'];
            }
            $product = $this->productRepository->find($productId);
            if ($product === null || !$product->isActive()) {
                return ['error' => 'Every quote line must reference an active product.'];
            }
            if ($product->getCurrency() !== $input->currency) {
                return ['error' => 'All products must use the quote currency.'];
            }
            if ($product->getUnitPriceMinor() > intdiv(PHP_INT_MAX, $quantity)) {
                return ['error' => 'Quote line total is too large.'];
            }
            $lineTotal = $product->getUnitPriceMinor() * $quantity;
            if ($lineTotal > PHP_INT_MAX - $total) {
                return ['error' => 'Quote total is too large.'];
            }
            $total += $lineTotal;
            $items[] = [
                'product_id' => $productId,
                'sku' => $product->getSku(),
                'description' => $product->getName(),
                'quantity' => $quantity,
                'unit_price_minor' => $product->getUnitPriceMinor(),
                'line_total_minor' => $lineTotal,
            ];
            $seen[$productId] = true;
        }
        if ($total < 1) {
            return ['error' => 'Quote total must be greater than zero.'];
        }
        return ['client_id' => $input->client_id, 'currency' => $input->currency, 'valid_until' => $validUntil, 'items' => $items, 'total_minor' => $total];
    }

    private function parseDate(string $value): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('Invalid calendar date.');
        }
        return $date;
    }

    /** @return array<string, int|string|bool|null> */
    private function serializeProduct(Product $product): array
    {
        return [
            'id' => $product->getId(),
            'sku' => $product->getSku(),
            'name' => $product->getName(),
            'description' => $product->getDescription(),
            'currency' => $product->getCurrency(),
            'unit_price' => $this->money->formatMinor($product->getUnitPriceMinor()),
            'active' => $product->isActive(),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeDocument(SalesDocument $document): array
    {
        return [
            'id' => $document->getId(),
            'type' => $document->getType(),
            'document_number' => $document->getDocumentNumber(),
            'client_id' => $document->getClient()->getId(),
            'client_name' => $document->getClient()->getTitle(),
            'source_quote_id' => $document->getSourceQuote()?->getId(),
            'currency' => $document->getCurrency(),
            'items' => array_map(fn (array $line): array => $line + [
                'unit_price' => $this->money->formatMinor($line['unit_price_minor']),
                'line_total' => $this->money->formatMinor($line['line_total_minor']),
            ], $document->getItems()),
            'total' => $this->money->formatMinor($document->getTotalMinor()),
            'status' => $document->getStatus(),
            'valid_until' => $document->getValidUntil()?->format('Y-m-d'),
            'created_at' => $document->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeOrder(SalesDocument $order): array
    {
        $data = $this->serializeDocument($order);
        $data['shipments'] = array_map(fn (Shipment $shipment): array => $this->serializeShipment($shipment), $this->shipmentRepository->findForOrder((int) $order->getId()));
        $invoice = $this->invoiceRepository->findOneBy(['salesOrder' => $order]);
        $data['invoice_id'] = $invoice?->getId();
        return $data;
    }

    /** @return array<string, mixed> */
    private function serializeShipment(Shipment $shipment): array
    {
        return [
            'id' => $shipment->getId(),
            'sales_order_id' => $shipment->getSalesOrder()->getId(),
            'reference' => $shipment->getReference(),
            'items' => $shipment->getItems(),
            'shipped_at' => $shipment->getShippedAt()->format('Y-m-d'),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeInvoice(Invoice $invoice): array
    {
        return [
            'id' => $invoice->getId(),
            'invoice_number' => $invoice->getInvoiceNumber(),
            'sales_order_id' => $invoice->getSalesOrder()?->getId(),
            'client_name' => $invoice->getClientName(),
            'currency' => $invoice->getCurrency(),
            'total' => $this->money->formatMinor($invoice->getTotalMinor()),
            'status' => $invoice->getStatus(),
        ];
    }

    /** @return array<string, int|string|null> */
    private function serializePayment(Payment $payment): array
    {
        return [
            'id' => $payment->getId(),
            'invoice_id' => $payment->getInvoice()->getId(),
            'amount' => $this->money->formatMinor($payment->getAmountMinor()),
            'currency' => $payment->getInvoice()->getCurrency(),
            'reference' => $payment->getReference(),
            'paid_at' => $payment->getPaidAt()->format('Y-m-d'),
        ];
    }
}