<?php

namespace App\Tests\Functional;

/** Quote-to-cash over HTTP: catalog, quotes, orders, shipments, stock dispatch and returns, invoicing, receipts. */
final class SalesFlowTest extends ApiTestCase
{
    private const LEDGER = ['accounts_receivable' => ['1100', 'asset'], 'revenue' => ['4000', 'revenue'], 'cash' => ['1000', 'asset']];

    private function product(string $sku = 'SKU-1', string $price = '25.00', string $currency = 'EUR'): int
    {
        self::assertSame(201, $this->callAs('finance', 'POST', '/api/products', json_encode(['sku' => $sku, 'name' => 'Widget '.$sku, 'currency' => $currency, 'unit_price' => $price])), $this->lastBody);

        return $this->json()['id'];
    }

    private function client(): int
    {
        self::assertSame(201, $this->callAs('admin', 'POST', '/api/clients', '{"title":"Acme","notes":"n"}'));

        return $this->json()['id'];
    }

    /** @return array<string, mixed> an accepted quote converted to a sales order for 4 widgets */
    private function order(int $clientId, int $productId, int $quantity = 4): array
    {
        $quote = json_encode(['client_id' => $clientId, 'currency' => 'EUR', 'items' => [['product_id' => $productId, 'quantity' => $quantity]]]);
        self::assertSame(201, $this->callAs('user', 'POST', '/api/quotes', $quote), $this->lastBody);
        $id = $this->json()['id'];
        $this->callAs('user', 'PATCH', '/api/quotes/'.$id.'/status', '{"status":"sent"}');
        $this->callAs('user', 'PATCH', '/api/quotes/'.$id.'/status', '{"status":"accepted"}');
        self::assertSame(201, $this->callAs('user', 'POST', '/api/quotes/'.$id.'/convert'), $this->lastBody);

        return $this->json();
    }

    public function test_the_product_catalog_is_maintained_by_finance_users_with_validation(): void
    {
        self::assertSame(403, $this->callAs('user', 'POST', '/api/products', '{"sku":"A","name":"A","currency":"EUR","unit_price":"1.00"}'));
        $id = $this->product();
        self::assertSame(409, $this->callAs('finance', 'POST', '/api/products', '{"sku":"SKU-1","name":"Again","currency":"EUR","unit_price":"1.00"}'), 'SKUs are unique');
        foreach (['{"name":"No SKU","currency":"EUR","unit_price":"1.00"}', '{"sku":"B","name":"B","currency":"EUR","unit_price":"1.005"}', '{"sku":"B","name":"B","currency":"EUR","unit_price":"-1.00"}', '{"sku":"B","name":"B","currency":"SEK","unit_price":"1.00"}'] as $body) {
            self::assertContains($this->callAs('finance', 'POST', '/api/products', $body), [400, 422], $body);
        }

        self::assertSame(200, $this->callAs('finance', 'PATCH', '/api/products/'.$id, '{"sku":"SKU-1","name":"Widget v2","currency":"EUR","unit_price":"30.00","description":"New","active":false}'));
        self::assertSame('30.00', $this->json()['unit_price']);
        self::assertFalse($this->json()['active']);
        $other = $this->product('SKU-2');
        self::assertSame(409, $this->callAs('finance', 'PATCH', '/api/products/'.$other, '{"sku":"SKU-1","name":"Clash","currency":"EUR","unit_price":"1.00"}'));
        self::assertSame(404, $this->callAs('finance', 'PATCH', '/api/products/999', '{"sku":"X","name":"X","currency":"EUR","unit_price":"1.00"}'));
        self::assertSame(200, $this->callAs('user', 'GET', '/api/products'));
        self::assertCount(2, $this->json());
    }

    public function test_quotes_are_priced_from_the_catalog_and_follow_the_status_workflow(): void
    {
        $clientId = $this->client();
        $productId = $this->product();
        $usd = $this->product('SKU-USD', '5.00', 'USD');
        $line = ['product_id' => $productId, 'quantity' => 3];
        $base = ['client_id' => $clientId, 'currency' => 'EUR', 'items' => [$line]];

        foreach ([['items' => []], ['items' => [$line, $line]], ['items' => [['product_id' => $productId, 'quantity' => 0]]], ['items' => [['product_id' => 999, 'quantity' => 1]]], ['items' => [['product_id' => $usd, 'quantity' => 1]]], ['valid_until' => 'soon']] as $invalid) {
            self::assertContains($this->callAs('user', 'POST', '/api/quotes', json_encode($invalid + $base)), [400, 422], json_encode($invalid));
        }
        self::assertSame(404, $this->callAs('user', 'POST', '/api/quotes', json_encode(['client_id' => 999] + $base)));

        self::assertSame(201, $this->callAs('user', 'POST', '/api/quotes', json_encode(['valid_until' => '2020-01-31'] + $base)));
        $expired = $this->json();
        self::assertSame('75.00', $expired['total'], 'three widgets at the 25.00 catalog price');
        self::assertSame('draft', $expired['status']);
        self::assertMatchesRegularExpression('/^QT-\d{4}-\d{6}$/', $expired['document_number']);
        $uri = '/api/quotes/'.$expired['id'];

        self::assertSame(409, $this->callAs('user', 'PATCH', $uri.'/status', '{"status":"accepted"}'), 'a draft must be sent first');
        self::assertSame(409, $this->callAs('user', 'POST', $uri.'/convert'), 'only accepted quotes convert');
        self::assertSame(200, $this->callAs('user', 'PATCH', $uri.'/status', '{"status":"sent"}'));
        self::assertSame(409, $this->callAs('user', 'PATCH', $uri.'/status', '{"status":"accepted"}'), 'an expired quote cannot be accepted');
        self::assertSame(200, $this->callAs('user', 'PATCH', $uri.'/status', '{"status":"rejected"}'));
        self::assertSame(404, $this->callAs('user', 'PATCH', '/api/quotes/999/status', '{"status":"sent"}'));
        self::assertSame(404, $this->callAs('user', 'POST', '/api/quotes/999/convert'));

        $order = $this->order($clientId, $productId);
        self::assertMatchesRegularExpression('/^SO-\d{4}-\d{6}$/', $order['document_number']);
        self::assertSame('100.00', $order['total']);
        self::assertSame(200, $this->callAs('user', 'GET', '/api/quotes'));
        $quotes = array_column($this->json(), 'id', 'status');
        $statuses = array_keys($quotes);
        sort($statuses);
        self::assertSame(['converted', 'rejected'], $statuses);
        self::assertSame(200, $this->callAs('user', 'POST', '/api/quotes/'.$quotes['converted'].'/convert'), 'converting twice returns the existing order');
        self::assertSame($order['id'], $this->json()['id']);
    }

    public function test_an_order_is_shipped_dispatched_invoiced_and_paid_with_ledger_postings(): void
    {
        $this->openLedger(self::LEDGER);
        $clientId = $this->client();
        $productId = $this->product();
        $order = $this->order($clientId, $productId);
        $shipments = '/api/sales-orders/'.$order['id'].'/shipments';
        $line = fn (int $quantity): string => json_encode(['shipped_at' => '2026-03-05', 'reference' => 'DHL', 'items' => [['product_id' => $productId, 'quantity' => $quantity]]]);

        self::assertSame(409, $this->callAs('user', 'POST', '/api/sales-orders/'.$order['id'].'/invoice', '{"invoice_date":"2026-03-06"}'), 'invoice only after full shipment');
        self::assertInvalid($this->callAs('user', 'POST', $shipments, '{"items":[]}'));
        self::assertInvalid($this->callAs('user', 'POST', $shipments, json_encode(['shipped_at' => 'tomorrow', 'items' => [['product_id' => $productId, 'quantity' => 1]]])));
        self::assertSame(409, $this->callAs('user', 'POST', $shipments, $line(5)), 'cannot ship more than ordered');
        self::assertSame(201, $this->callAs('user', 'POST', $shipments, $line(1)), $this->lastBody);
        $first = $this->json()['id'];
        self::assertSame(201, $this->callAs('user', 'POST', $shipments, $line(3)));
        $second = $this->json()['id'];
        self::assertSame(409, $this->callAs('user', 'POST', $shipments, $line(1)), 'the order is fully shipped');
        self::assertSame(404, $this->callAs('user', 'POST', '/api/sales-orders/999/shipments', $line(1)));
        self::assertSame(200, $this->callAs('user', 'GET', '/api/sales-orders'));
        self::assertSame('shipped', $this->json()[0]['status']);

        // Stock leaves a warehouse only through an explicit dispatch by an inventory user.
        $this->callAs('admin', 'POST', '/api/inventory/warehouses', '{"code":"MAIN","name":"Main"}');
        $warehouse = $this->json()['id'];
        $dispatch = json_encode(['warehouse_id' => $warehouse, 'moved_at' => '2026-03-05']);
        self::assertSame(200, $this->callAs('user', 'GET', '/api/inventory/sales-shipments/pending'));
        self::assertCount(2, $this->json());
        self::assertSame(409, $this->callAs('inventory', 'POST', '/api/inventory/sales-shipments/'.$first.'/dispatch', $dispatch), 'no stock on hand');
        self::assertSame(201, $this->callAs('admin', 'POST', '/api/inventory/adjustments', json_encode(['product_id' => $productId, 'warehouse_id' => $warehouse, 'quantity_delta' => 4, 'reason' => 'Opening count', 'moved_at' => '2026-03-01'])), $this->lastBody);
        self::assertInvalid($this->callAs('inventory', 'POST', '/api/inventory/sales-shipments/'.$first.'/dispatch', '{"warehouse_id":999}'));
        self::assertSame(200, $this->callAs('inventory', 'POST', '/api/inventory/sales-shipments/'.$first.'/dispatch', $dispatch), $this->lastBody);
        self::assertSame(409, $this->callAs('inventory', 'POST', '/api/inventory/sales-shipments/'.$first.'/dispatch', $dispatch), 'a shipment is dispatched once');
        self::assertSame(200, $this->callAs('inventory', 'POST', '/api/inventory/sales-shipments/'.$second.'/dispatch', $dispatch));
        self::assertSame(404, $this->callAs('inventory', 'POST', '/api/inventory/sales-shipments/999/dispatch', $dispatch));
        self::assertSame(200, $this->callAs('user', 'GET', '/api/inventory/stock'));
        self::assertSame([], $this->json(), 'everything on hand was dispatched');
        self::assertSame(200, $this->callAs('user', 'GET', '/api/inventory/sales-shipments/dispatched'));
        self::assertCount(2, $this->json());

        // A customer return brings stock back, bounded by what was dispatched.
        $return = fn (int $quantity): string => json_encode(['return_type' => 'customer', 'source_type' => 'sales_shipment', 'source_id' => $second, 'warehouse_id' => $warehouse, 'returned_at' => '2026-03-08', 'items' => [['product_id' => $productId, 'quantity' => $quantity]]]);
        self::assertSame(409, $this->callAs('inventory', 'POST', '/api/inventory/returns', $return(4)), 'cannot return more than was shipped');
        self::assertSame(201, $this->callAs('inventory', 'POST', '/api/inventory/returns', $return(2)), $this->lastBody);
        self::assertSame(409, $this->callAs('inventory', 'POST', '/api/inventory/returns', $return(2)), 'only one unit is still returnable');
        self::assertInvalid($this->callAs('inventory', 'POST', '/api/inventory/returns', json_encode(['return_type' => 'customer', 'source_type' => 'purchase_order', 'source_id' => 1, 'warehouse_id' => $warehouse, 'items' => [['product_id' => $productId, 'quantity' => 1]]])));
        self::assertSame(200, $this->callAs('user', 'GET', '/api/inventory/returns'));
        self::assertCount(1, $this->json());
        self::assertSame(200, $this->callAs('user', 'GET', '/api/inventory/movements'));
        self::assertCount(4, $this->json(), 'adjustment, two dispatches and one return');

        // Invoice the order, issue it and settle it in two receipts.
        $invoiceUri = '/api/sales-orders/'.$order['id'].'/invoice';
        self::assertContains($this->callAs('user', 'POST', $invoiceUri, '{}'), [400, 422]);
        self::assertInvalid($this->callAs('user', 'POST', $invoiceUri, '{"invoice_date":"2026-03-06","due_date":"2026-03-01"}'));
        self::assertSame(201, $this->callAs('user', 'POST', $invoiceUri, '{"invoice_date":"2026-03-06","due_date":"2026-04-05"}'), $this->lastBody);
        $invoice = $this->json();
        self::assertSame('100.00', $invoice['total']);
        self::assertSame(200, $this->callAs('user', 'POST', $invoiceUri, '{"invoice_date":"2026-03-07"}'), 'invoicing twice returns the same draft');
        self::assertSame($invoice['id'], $this->json()['id']);
        $payments = '/api/invoices/'.$invoice['id'].'/payments';

        self::assertSame(409, $this->callAs('finance', 'POST', $payments, '{"amount":"10.00","paid_at":"2026-03-10"}'), 'drafts cannot be paid');
        self::assertSame(200, $this->callAs('finance', 'POST', '/api/invoices/'.$invoice['id'].'/issue'), $this->lastBody);
        self::assertSame('issued', $this->json()['status']);
        foreach (['{"amount":"0.00","paid_at":"2026-03-10"}', '{"amount":"10.00"}', '{"amount":"10.00","paid_at":"10.03.2026"}'] as $body) {
            self::assertContains($this->callAs('finance', 'POST', $payments, $body), [400, 422], $body);
        }
        self::assertSame(409, $this->callAs('finance', 'POST', $payments, '{"amount":"10.00","paid_at":"2027-03-10"}'), 'no open period');
        self::assertSame(409, $this->callAs('finance', 'POST', $payments, '{"amount":"100.01","paid_at":"2026-03-10"}'), 'no overpayment');
        self::assertSame(201, $this->callAs('finance', 'POST', $payments, '{"amount":"40.00","paid_at":"2026-03-10","reference":"BT-1"}'), $this->lastBody);
        self::assertSame('60.00', $this->json()['balance_due']);
        self::assertSame(200, $this->callAs('user', 'GET', '/api/invoices/'.$invoice['id']));
        self::assertSame('partially_paid', $this->json()['status']);
        self::assertSame(201, $this->callAs('finance', 'POST', $payments, '{"amount":"60.00","paid_at":"2026-03-12"}'));
        self::assertSame('0.00', $this->json()['balance_due']);
        self::assertSame(409, $this->callAs('finance', 'POST', $payments, '{"amount":"0.01","paid_at":"2026-03-13"}'), 'a paid invoice takes no more receipts');
        self::assertSame(404, $this->callAs('finance', 'POST', '/api/invoices/999/payments', '{"amount":"1.00","paid_at":"2026-03-13"}'));
        self::assertSame(200, $this->callAs('finance', 'GET', $payments));
        self::assertCount(2, $this->json());

        self::assertSame(200, $this->callAs('finance', 'GET', '/api/finance/reports/subledger-reconciliation?currency=EUR'));
        self::assertTrue($this->json()['reconciled'], $this->lastBody);
        self::assertSame('0.00', $this->json()['receivables']['difference']);
        self::assertSame(409, $this->callAs('admin', 'DELETE', '/api/clients/'.$clientId), 'a client with orders cannot be deleted');
    }
}
