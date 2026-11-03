<?php

namespace App\Tests\Functional;

/** Purchase approval, landed costs, weighted-average valuation and three-way bill matching over HTTP. */
final class PurchasingFlowTest extends ApiTestCase
{
    /** @return array<string, int> mapped account ids by role */
    private function configureLedger(bool $withInventoryAccounts = true): array
    {
        $this->callAs('admin', 'POST', '/api/finance/periods', '{"name":"2026","starts_on":"2026-01-01","ends_on":"2026-12-31"}');
        $roles = ['accounts_payable' => ['2000', 'liability'], 'project_expense' => ['5000', 'expense']];
        if ($withInventoryAccounts) {
            $roles += [
                'inventory' => ['1300', 'asset'], 'cost_of_sales' => ['5100', 'expense'], 'goods_received_not_invoiced' => ['2150', 'liability'],
                'inventory_adjustment' => ['5200', 'expense'], 'purchase_price_variance' => ['5300', 'expense'],
            ];
        }
        $ids = [];
        foreach ($roles as $role => [$code, $type]) {
            self::assertSame(201, $this->callAs('admin', 'POST', '/api/finance/accounts', json_encode(['code' => $code, 'name' => $role, 'type' => $type])));
            $ids[$role] = $this->json()['id'];
        }
        self::assertSame(200, $this->callAs('admin', 'PUT', '/api/finance/account-mappings', json_encode($ids)));

        return $ids;
    }

    /** @return array{supplier: int, warehouse: int, product: int} */
    private function catalogue(): array
    {
        $this->callAs('user', 'POST', '/api/inventory/suppliers', '{"code":"SUP","name":"Supplier Ltd"}');
        $supplier = $this->json()['id'];
        $this->callAs('user', 'POST', '/api/inventory/warehouses', '{"code":"MAIN","name":"Main"}');
        $warehouse = $this->json()['id'];
        $this->callAs('finance', 'POST', '/api/products', '{"sku":"SKU-1","name":"Widget","currency":"EUR","unit_price":"25.00"}');

        return ['supplier' => $supplier, 'warehouse' => $warehouse, 'product' => $this->json()['id']];
    }

    private function createOrder(string $as, array $catalogue, int $quantity = 10, string $unitCost = '10.00'): array
    {
        $body = json_encode(['supplier_id' => $catalogue['supplier'], 'currency' => 'EUR', 'ordered_at' => '2026-03-01', 'items' => [['product_id' => $catalogue['product'], 'quantity' => $quantity, 'unit_cost' => $unitCost]]]);
        self::assertSame(201, $this->callAs($as, 'POST', '/api/inventory/purchase-orders', $body));

        return $this->json();
    }

    public function test_orders_above_the_threshold_need_approval_by_someone_other_than_the_creator(): void
    {
        $catalogue = $this->catalogue();
        self::assertSame(403, $this->callAs('finance', 'PUT', '/api/inventory/purchase-approval-policy', '{"threshold":"50.00"}'), 'only administrators set the policy');
        self::assertSame(200, $this->callAs('admin', 'PUT', '/api/inventory/purchase-approval-policy', '{"threshold":"50.00"}'));

        $order = $this->createOrder('user', $catalogue);
        self::assertSame('pending_approval', $order['status']);
        $receipt = json_encode(['warehouse_id' => $catalogue['warehouse'], 'received_at' => '2026-03-02', 'items' => [['product_id' => $catalogue['product'], 'quantity' => 1]]]);
        self::assertSame(409, $this->callAs('user', 'POST', '/api/inventory/purchase-orders/'.$order['id'].'/receipts', $receipt), 'goods cannot be received before approval');

        self::assertSame(403, $this->callAs('user', 'POST', '/api/inventory/purchase-orders/'.$order['id'].'/approve'), 'ordinary users cannot approve');
        self::assertSame(200, $this->callAs('finance', 'POST', '/api/inventory/purchase-orders/'.$order['id'].'/approve', '{"comment":"Budgeted"}'));
        self::assertSame('open', $this->json()['status']);
        self::assertSame(409, $this->callAs('finance', 'POST', '/api/inventory/purchase-orders/'.$order['id'].'/approve'), 'a decision is final');
        self::assertSame(201, $this->callAs('user', 'POST', '/api/inventory/purchase-orders/'.$order['id'].'/receipts', $receipt));

        $own = $this->createOrder('finance', $catalogue);
        self::assertSame(403, $this->callAs('finance', 'POST', '/api/inventory/purchase-orders/'.$own['id'].'/approve'), 'creators cannot approve their own order');
        self::assertSame(400, $this->callAs('admin', 'POST', '/api/inventory/purchase-orders/'.$own['id'].'/reject'), 'rejection needs a comment');
        self::assertSame(200, $this->callAs('admin', 'POST', '/api/inventory/purchase-orders/'.$own['id'].'/reject', '{"comment":"Too expensive"}'));
        self::assertSame('rejected', $this->json()['status']);
        $this->callAs('user', 'GET', '/api/inventory/purchase-orders/'.$own['id'].'/approvals');
        self::assertSame(['rejected'], array_column($this->json(), 'decision'));
    }

    public function test_without_a_threshold_orders_need_no_approval(): void
    {
        self::assertSame('open', $this->createOrder('user', $this->catalogue())['status']);
    }

    public function test_receiving_with_landed_costs_values_stock_and_bills_clear_the_accrual(): void
    {
        $this->configureLedger();
        $catalogue = $this->catalogue();
        $order = $this->createOrder('user', $catalogue);
        $receipt = ['warehouse_id' => $catalogue['warehouse'], 'received_at' => '2026-03-02', 'items' => [['product_id' => $catalogue['product'], 'quantity' => 10]], 'landed_costs' => [['description' => 'Freight', 'amount' => '50.00']]];

        self::assertSame(403, $this->callAs('user', 'POST', '/api/inventory/purchase-orders/'.$order['id'].'/receipts', json_encode($receipt)), 'landed costs are finance-only');
        self::assertSame(201, $this->callAs('finance', 'POST', '/api/inventory/purchase-orders/'.$order['id'].'/receipts', json_encode($receipt)));
        self::assertSame('50.00', $this->json()['landed_total']);

        self::assertSame(403, $this->callAs('user', 'GET', '/api/inventory/valuation'));
        self::assertSame(200, $this->callAs('finance', 'GET', '/api/inventory/valuation'));
        $report = $this->json();
        self::assertTrue($report['enabled']);
        self::assertSame('150.00', $report['rows'][0]['value']);
        self::assertSame('15.00', $report['rows'][0]['average_unit_cost']);
        self::assertSame(0, $report['rows'][0]['unvalued_quantity']);

        // A stock-count loss leaves at the 15.00 average.
        self::assertSame(201, $this->callAs('admin', 'POST', '/api/inventory/adjustments', json_encode(['product_id' => $catalogue['product'], 'warehouse_id' => $catalogue['warehouse'], 'quantity_delta' => -2, 'reason' => 'Damaged', 'moved_at' => '2026-03-03'])));
        $this->callAs('finance', 'GET', '/api/inventory/valuation');
        self::assertSame('120.00', $this->json()['rows'][0]['value']);

        // Three-way match: 100.00 of goods + 50.00 freight is available to bill.
        $bill = ['vendor_name' => 'Supplier Ltd', 'vendor_reference' => 'INV-1', 'currency' => 'EUR', 'total' => '150.00', 'bill_date' => '2026-03-05', 'purchase_order_id' => $order['id']];
        self::assertSame(201, $this->callAs('finance', 'POST', '/api/finance/payables', json_encode($bill)));
        self::assertSame('matched', $this->json()['match_status']);
        self::assertSame('150.00', $this->json()['accrual_cleared']);

        $extra = ['vendor_reference' => 'INV-2', 'total' => '1.00'] + $bill;
        self::assertSame(409, $this->callAs('finance', 'POST', '/api/finance/payables', json_encode($extra)), 'nothing left to bill');
        self::assertSame(201, $this->callAs('finance', 'POST', '/api/finance/payables', json_encode($extra + ['override_reason' => 'Late fee agreed'])));
        self::assertSame('exception', $this->json()['match_status']);
        self::assertSame('1.00', $this->json()['price_variance']);

        self::assertSame(200, $this->callAs('finance', 'GET', '/api/finance/reports/subledger-reconciliation?currency=EUR'));
        $reconciliation = $this->json();
        self::assertSame('0.00', $reconciliation['inventory']['difference']);
        self::assertSame('0.00', $reconciliation['goods_received_not_invoiced']['difference']);
        self::assertTrue($reconciliation['reconciled'], json_encode($reconciliation));
    }

    public function test_bill_matching_rejects_the_wrong_supplier_currency_or_unapproved_order(): void
    {
        $this->configureLedger(false);
        $catalogue = $this->catalogue();
        $order = $this->createOrder('user', $catalogue);
        $receipt = ['warehouse_id' => $catalogue['warehouse'], 'received_at' => '2026-03-02', 'items' => [['product_id' => $catalogue['product'], 'quantity' => 4]]];
        self::assertSame(201, $this->callAs('user', 'POST', '/api/inventory/purchase-orders/'.$order['id'].'/receipts', json_encode($receipt)));
        $bill = ['vendor_name' => 'Supplier Ltd', 'vendor_reference' => 'INV-1', 'currency' => 'EUR', 'total' => '40.00', 'bill_date' => '2026-03-05', 'purchase_order_id' => $order['id']];

        self::assertSame(400, $this->callAs('finance', 'POST', '/api/finance/payables', json_encode(['vendor_name' => 'Someone Else'] + $bill)));
        self::assertSame(400, $this->callAs('finance', 'POST', '/api/finance/payables', json_encode(['currency' => 'GBP'] + $bill)));
        self::assertSame(404, $this->callAs('finance', 'POST', '/api/finance/payables', json_encode(['purchase_order_id' => 9999] + $bill)));
        // Without valuation accounts the bill is expensed as before, but is still matched.
        self::assertSame(201, $this->callAs('finance', 'POST', '/api/finance/payables', json_encode($bill)));
        self::assertSame('matched', $this->json()['match_status']);
        self::assertSame('0.00', $this->json()['accrual_cleared']);
    }

    public function test_stock_is_quantity_only_until_the_inventory_accounts_are_mapped(): void
    {
        $this->configureLedger(false);
        $catalogue = $this->catalogue();
        $order = $this->createOrder('user', $catalogue);
        self::assertSame(201, $this->callAs('user', 'POST', '/api/inventory/purchase-orders/'.$order['id'].'/receipts', json_encode(['warehouse_id' => $catalogue['warehouse'], 'received_at' => '2026-03-02', 'items' => [['product_id' => $catalogue['product'], 'quantity' => 3]]])));

        $this->callAs('finance', 'GET', '/api/inventory/valuation');
        self::assertFalse($this->json()['enabled']);
        self::assertSame([], $this->json()['rows']);
        self::assertSame(409, $this->callAs('finance', 'POST', '/api/inventory/opening-valuations', json_encode(['product_id' => $catalogue['product'], 'warehouse_id' => $catalogue['warehouse'], 'unit_cost' => '5.00', 'currency' => 'EUR'])));
    }
}
