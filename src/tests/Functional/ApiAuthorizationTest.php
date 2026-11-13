<?php

namespace App\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;

/** Exercises real HTTP routing, firewall and role checks (allowed and denied) for the role model. */
final class ApiAuthorizationTest extends ApiTestCase
{
    public function test_anonymous_requests_are_rejected(): void
    {
        self::assertSame(401, $this->callAs(null, 'GET', '/api/clients'));
        self::assertSame(401, $this->callAs(null, 'GET', '/api/finance/currencies'));
    }

    public function test_sign_in_is_throttled_after_repeated_failures_and_a_success_resets_the_count(): void
    {
        $email = 'login-'.bin2hex(random_bytes(6)).'@example.test';
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist((new \App\Entity\User())->setEmail($email)->setPasswordHash(password_hash('correct horse', PASSWORD_BCRYPT, ['cost' => 4])));
        $entityManager->flush();
        $wrong = json_encode(['email' => $email, 'password' => 'nope']);
        $right = json_encode(['email' => $email, 'password' => 'correct horse']);

        self::assertSame(401, $this->callAs(null, 'POST', '/api/login', $wrong));
        self::assertSame(200, $this->callAs(null, 'POST', '/api/login', $right), substr(strip_tags($this->lastBody), 0, 400));
        self::assertArrayHasKey('token', $this->json());
        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            self::assertSame(401, $this->callAs(null, 'POST', '/api/login', $wrong), 'attempt '.$attempt.' (the earlier failure was reset)');
        }
        self::assertSame(429, $this->callAs(null, 'POST', '/api/login', $wrong));
        self::assertSame(429, $this->callAs(null, 'POST', '/api/login', $right), 'a locked account stays locked even with the right password');
        self::assertGreaterThan(0, (int) $this->client->getResponse()->headers->get('Retry-After'));
        self::assertSame(400, $this->callAs(null, 'POST', '/api/login', '{"email":"only"}'));
    }

    public function test_ledger_data_requires_finance_or_admin_role(): void
    {
        foreach (['/api/finance/journal-entries', '/api/finance/payables', '/api/finance/account-mappings', '/api/finance/reports/reconciliation'] as $uri) {
            self::assertSame(403, $this->callAs('user', 'GET', $uri), $uri.' as user');
            self::assertSame(200, $this->callAs('finance', 'GET', $uri), $uri.' as finance');
            self::assertSame(200, $this->callAs('admin', 'GET', $uri), $uri.' as admin');
        }
        self::assertSame(200, $this->callAs('user', 'GET', '/api/finance/currencies'));
    }

    public function test_ledger_configuration_and_period_control_stay_administrator_only(): void
    {
        foreach ([['PUT', '/api/finance/account-mappings'], ['POST', '/api/finance/periods'], ['POST', '/api/finance/opening-balances'], ['POST', '/api/finance/accounts']] as [$method, $uri]) {
            self::assertSame(403, $this->callAs('user', $method, $uri), $uri.' as user');
            self::assertSame(403, $this->callAs('finance', $method, $uri), $uri.' as finance');
            self::assertNotSame(403, $this->callAs('admin', $method, $uri), $uri.' as admin');
        }
    }

    public function test_invoice_issue_and_customer_payments_need_finance_role(): void
    {
        self::assertSame(403, $this->callAs('user', 'POST', '/api/invoices/1/issue'));
        self::assertSame(404, $this->callAs('finance', 'POST', '/api/invoices/1/issue'));
        self::assertSame(403, $this->callAs('user', 'GET', '/api/invoices/1/payments'));
        self::assertSame(404, $this->callAs('finance', 'GET', '/api/invoices/1/payments'));
    }

    public function test_cost_and_profitability_data_is_hidden_from_regular_users(): void
    {
        self::assertSame(403, $this->callAs('user', 'GET', '/api/operations/projects/1/profitability?currency=EUR'));
        self::assertSame(404, $this->callAs('finance', 'GET', '/api/operations/projects/1/profitability?currency=EUR'));
        self::assertSame(403, $this->callAs('finance', 'PUT', '/api/operations/users/1/cost-rate'));
    }

    public function test_record_history_and_recovery_are_administrator_only(): void
    {
        self::assertSame(403, $this->callAs('user', 'GET', '/api/records/revisions'));
        self::assertSame(403, $this->callAs('finance', 'GET', '/api/records/revisions'));
        self::assertSame(200, $this->callAs('admin', 'GET', '/api/records/revisions'));
        self::assertSame(403, $this->callAs('finance', 'POST', '/api/records/revisions/1/restore'));
        self::assertSame(404, $this->callAs('admin', 'POST', '/api/records/revisions/1/restore'));
        self::assertSame(400, $this->callAs('admin', 'GET', '/api/records/revisions?type=invoice'));
    }

    public function test_stock_changing_inventory_routes_need_the_inventory_role(): void
    {
        $routes = [
            ['POST', '/api/inventory/purchase-orders'],
            ['POST', '/api/inventory/purchase-orders/1/receipts'],
            ['POST', '/api/inventory/sales-shipments/1/dispatch'],
            ['POST', '/api/inventory/returns'],
        ];
        foreach ($routes as [$method, $uri]) {
            self::assertSame(403, $this->callAs('user', $method, $uri), $uri.' as user');
            self::assertSame(403, $this->callAs('finance', $method, $uri), $uri.' as finance');
            self::assertNotContains($this->callAs('inventory', $method, $uri), [401, 403], $uri.' as inventory');
            self::assertNotContains($this->callAs('admin', $method, $uri), [401, 403], $uri.' as admin');
        }
        foreach (['/api/inventory/stock', '/api/inventory/movements', '/api/inventory/purchase-orders', '/api/inventory/warehouses', '/api/inventory/suppliers', '/api/inventory/returns'] as $uri) {
            self::assertSame(200, $this->callAs('user', 'GET', $uri), $uri.' stays readable');
        }
    }

    public function test_warehouse_and_supplier_master_data_is_administrator_only(): void
    {
        foreach ([['/api/inventory/warehouses', '{"code":"MAIN","name":"Main"}'], ['/api/inventory/suppliers', '{"code":"SUP","name":"Supplier Ltd"}']] as [$uri, $body]) {
            foreach (['user', 'finance', 'inventory'] as $role) {
                self::assertSame(403, $this->callAs($role, 'POST', $uri, $body), $uri.' as '.$role);
                self::assertSame(403, $this->callAs($role, 'PATCH', $uri.'/1', $body), $uri.'/1 as '.$role);
            }
            self::assertSame(201, $this->callAs('admin', 'POST', $uri, $body));
            self::assertSame(200, $this->callAs('admin', 'PATCH', $uri.'/'.$this->json()['id'], $body));
        }
        self::assertSame(403, $this->callAs('inventory', 'POST', '/api/inventory/adjustments'), 'count adjustments stay with administrators');
    }

    public function test_a_project_with_time_or_expenses_cannot_be_deleted(): void
    {
        $this->callAs('admin', 'POST', '/api/clients', '{"title":"Acme","notes":"n"}');
        $clientId = $this->json()['id'];
        self::assertSame(201, $this->callAs('user', 'POST', '/api/projects', sprintf('{"title":"Build","description":"d","client_id":%d,"status":"active"}', $clientId)), $this->lastBody);
        $busy = $this->json()['id'];
        self::assertSame(201, $this->callAs('user', 'POST', '/api/projects', sprintf('{"title":"Empty","description":"d","client_id":%d,"status":"active"}', $clientId)));
        $empty = $this->json()['id'];
        self::assertSame(201, $this->callAs('user', 'POST', '/api/operations/time-entries', sprintf('{"project_id":%d,"work_date":"2026-03-02","duration_minutes":60,"description":"Work","billable":false}', $busy)), $this->lastBody);

        self::assertSame(409, $this->callAs('user', 'DELETE', '/api/projects/'.$busy));
        self::assertStringContainsString('1 time entries', $this->json()['error']);
        self::assertSame(200, $this->callAs('user', 'GET', '/api/projects/'.$busy));
        self::assertContains($this->callAs('user', 'DELETE', '/api/projects/'.$empty), [200, 204]);
        self::assertSame(404, $this->callAs('user', 'GET', '/api/projects/'.$empty));
    }

    public function test_a_client_with_quotes_cannot_be_deleted_and_a_deleted_client_can_be_recovered_with_its_contacts(): void
    {
        $this->callAs('admin', 'POST', '/api/clients', '{"title":"Acme","notes":"n"}');
        $clientId = $this->json()['id'];
        self::assertSame(201, $this->callAs('admin', 'POST', '/api/clients/'.$clientId.'/contacts', '{"name":"Ann","email":"ann@acme.test"}'), $this->lastBody);
        self::assertSame(201, $this->callAs('finance', 'POST', '/api/products', '{"sku":"SKU-1","name":"Widget","currency":"EUR","unit_price":"25.00"}'));
        $quote = sprintf('{"client_id":%d,"currency":"EUR","items":[{"product_id":%d,"quantity":1}]}', $clientId, $this->json()['id']);
        self::assertSame(201, $this->callAs('user', 'POST', '/api/quotes', $quote), $this->lastBody);
        self::assertSame(409, $this->callAs('admin', 'DELETE', '/api/clients/'.$clientId));
        self::assertStringContainsString('1 quotes or orders', $this->json()['error']);

        $this->callAs('admin', 'POST', '/api/clients', '{"title":"Beta","notes":"n"}');
        $betaId = $this->json()['id'];
        $this->callAs('admin', 'POST', '/api/clients/'.$betaId.'/contacts', '{"name":"Bob","email":"bob@beta.test"}');
        self::assertSame(204, $this->callAs('admin', 'DELETE', '/api/clients/'.$betaId));
        $this->callAs('admin', 'GET', '/api/records/revisions?type=client_contact&action=delete');
        self::assertSame(['record_type' => 'client', 'record_id' => $betaId], $this->json()[0]['cause']);

        $this->callAs('admin', 'GET', '/api/records/revisions?type=client&action=delete&entity_id='.$betaId);
        $revisionId = $this->json()[0]['id'];
        self::assertSame(400, $this->callAs('admin', 'POST', '/api/records/revisions/'.$revisionId.'/restore', '{"with_related":"yes"}'));
        self::assertSame(200, $this->callAs('admin', 'POST', '/api/records/revisions/'.$revisionId.'/restore'), $this->lastBody);
        self::assertTrue($this->json()['recreated']);
    }

    public function test_client_writes_remain_administrator_only(): void
    {
        self::assertSame(200, $this->callAs('user', 'GET', '/api/clients'));
        self::assertSame(403, $this->callAs('user', 'POST', '/api/clients', '{"title":"A","notes":"B"}'));
        self::assertSame(201, $this->callAs('admin', 'POST', '/api/clients', '{"title":"A","notes":"B"}'));
    }

    public function test_client_tax_identifiers_are_visible_to_finance_and_admin_only(): void
    {
        $body = '{"title":"Acme","notes":"n","legal_name":"Acme SIA","registration_number":"40003123456","vat_number":"lv40003123456","country_code":"lv","billing_email":"ap@acme.test","website":"https://acme.test","account_status":"prospect"}';
        self::assertSame(201, $this->callAs('admin', 'POST', '/api/clients', $body));
        $created = json_decode($this->lastBody, true);
        self::assertSame('LV40003123456', $created['vat_number']);
        self::assertSame('LV', $created['country_code']);

        self::assertSame(200, $this->callAs('user', 'GET', '/api/clients/'.$created['id']));
        $asUser = json_decode($this->lastBody, true);
        self::assertSame('Acme SIA', $asUser['legal_name']);
        self::assertNull($asUser['vat_number']);
        self::assertNull($asUser['billing_email']);
        self::assertTrue($asUser['financial_details_restricted']);

        self::assertSame(200, $this->callAs('finance', 'GET', '/api/clients/'.$created['id']));
        self::assertSame('40003123456', json_decode($this->lastBody, true)['registration_number']);
    }

    public function test_the_audit_trail_records_writes_refusals_and_reads_of_restricted_client_details(): void
    {
        $this->callAs('admin', 'POST', '/api/clients', '{"title":"Acme","notes":"n","vat_number":"LV12345678"}');
        $id = $this->json()['id'];
        $write = array_values(array_filter($this->auditRecords(), static fn (array $r): bool => ($r['context']['action'] ?? null) === 'api.write'));
        self::assertCount(1, $write);
        self::assertSame(['route' => 'api_clients_create', 'method' => 'POST', 'status' => 201, 'actor' => 'admin@example.test'], array_intersect_key($write[0]['context'], array_flip(['route', 'method', 'status', 'actor'])));
        self::assertStringNotContainsString('LV12345678', json_encode($this->auditRecords()), 'request bodies never reach the audit trail');

        $this->callAs('finance', 'GET', '/api/clients/'.$id);
        $shown = array_column(array_column($this->auditRecords(), 'context'), null, 'action')['client.show'];
        self::assertTrue($shown['restricted_fields_disclosed']);
        self::assertSame('finance@example.test', $shown['actor']);
        $this->callAs('user', 'GET', '/api/clients/'.$id);
        self::assertFalse(array_column(array_column($this->auditRecords(), 'context'), null, 'action')['client.show']['restricted_fields_disclosed']);
        $this->callAs('finance', 'GET', '/api/clients');
        self::assertTrue(array_column(array_column($this->auditRecords(), 'context'), null, 'action')['client.list']['restricted_fields_disclosed']);

        self::assertSame(403, $this->callAs('user', 'DELETE', '/api/clients/'.$id));
        $refused = array_column(array_column($this->auditRecords(), 'context'), null, 'action')['api.refused'];
        self::assertSame(['route' => 'api_clients_delete', 'status' => 403, 'actor' => 'user@example.test', 'record_ids' => ['id' => $id]], array_intersect_key($refused, array_flip(['route', 'status', 'actor', 'record_ids'])));
        self::assertSame(401, $this->callAs(null, 'GET', '/api/clients'));
        self::assertSame('warning', $this->auditRecords()[0]['level']);
        self::assertNull($this->auditRecords()[0]['context']['actor']);
    }

    public function test_client_search_does_not_reveal_restricted_identifiers_to_regular_users(): void
    {
        $this->callAs('admin', 'POST', '/api/clients', '{"title":"Acme","notes":"n","vat_number":"LV99887766"}');

        $this->callAs('user', 'GET', '/api/clients?q=99887766');
        self::assertSame([], json_decode($this->lastBody, true));
        $this->callAs('finance', 'GET', '/api/clients?q=99887766');
        self::assertCount(1, json_decode($this->lastBody, true));
        $this->callAs('user', 'GET', '/api/clients?q=acme');
        self::assertCount(1, json_decode($this->lastBody, true));
    }

    public function test_invalid_client_profile_values_are_rejected(): void
    {
        foreach (['"billing_email":"nope"', '"website":"ftp://x"', '"vat_number":"<script>"', '"country_code":"LVA"', '"account_status":"vip"'] as $field) {
            self::assertContains($this->callAs('admin', 'POST', '/api/clients', '{"title":"A","notes":"B",'.$field.'}'), [400, 422], $field);
        }
        self::assertSame(400, $this->callAs('admin', 'GET', '/api/clients?account_status=vip'));
    }

    public function test_a_deal_moves_through_the_pipeline_with_history_and_a_forecast(): void
    {
        $this->callAs('admin', 'POST', '/api/clients', '{"title":"Acme","notes":"n"}');
        $clientId = json_decode($this->lastBody, true)['id'];

        self::assertSame(201, $this->callAs('user', 'POST', '/api/deals', sprintf('{"client_id":%d,"title":"Rebuild","amount":"1000.50","currency":"EUR"}', $clientId)));
        $deal = json_decode($this->lastBody, true);
        self::assertSame('lead', $deal['stage']);
        self::assertSame('1000.50', $deal['amount']);

        self::assertSame(200, $this->callAs('user', 'POST', '/api/deals/'.$deal['id'].'/stage', '{"stage":"proposal"}'));
        self::assertSame(409, $this->callAs('user', 'POST', '/api/deals/'.$deal['id'].'/stage', '{"stage":"lost"}'), 'a lost deal needs a reason');
        self::assertSame(200, $this->callAs('user', 'GET', '/api/deals/summary'));
        $stages = array_column(json_decode($this->lastBody, true)['stages'], null, 'stage');
        self::assertSame(1, $stages['proposal']['count']);
        self::assertSame('500.25', $stages['proposal']['values'][0]['weighted_amount']);

        self::assertSame(200, $this->callAs('user', 'POST', '/api/deals/'.$deal['id'].'/stage', '{"stage":"won"}'));
        self::assertSame(409, $this->callAs('user', 'PATCH', '/api/deals/'.$deal['id'], '{"title":"Renamed"}'), 'closed deals are final');
        $this->callAs('user', 'GET', '/api/deals/'.$deal['id'].'/history');
        self::assertSame(['lead', 'proposal', 'won'], array_column(json_decode($this->lastBody, true), 'to_stage'));
    }

    public function test_deal_validation_and_delete_permissions(): void
    {
        self::assertSame(400, $this->callAs('user', 'POST', '/api/deals', '{"title":"No client"}'));
        self::assertSame(404, $this->callAs('user', 'POST', '/api/deals', '{"client_id":999,"title":"x"}'));
        $this->callAs('admin', 'POST', '/api/clients', '{"title":"Acme","notes":"n"}');
        $clientId = json_decode($this->lastBody, true)['id'];
        self::assertSame(400, $this->callAs('user', 'POST', '/api/deals', sprintf('{"client_id":%d,"title":"x","amount":"1.234","currency":"EUR"}', $clientId)));
        self::assertSame(400, $this->callAs('user', 'POST', '/api/deals', sprintf('{"client_id":%d,"title":"x","amount":"10.00"}', $clientId)));
        $this->callAs('user', 'POST', '/api/deals', sprintf('{"client_id":%d,"title":"Keep"}', $clientId));
        $id = json_decode($this->lastBody, true)['id'];
        self::assertSame(403, $this->callAs('user', 'DELETE', '/api/deals/'.$id));
        self::assertSame(204, $this->callAs('admin', 'DELETE', '/api/deals/'.$id));
        self::assertSame(404, $this->callAs('user', 'GET', '/api/deals/'.$id));
    }

    public function test_notifications_are_private_and_preferences_are_validated(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $notification = new \App\Entity\Notification($this->users['user'], 'overdue', 'task', 1, 5, 'Task is overdue.', 'task:1:overdue:2026-11-01');
        $entityManager->persist($notification);
        $entityManager->flush();
        $id = $notification->getId();
        static::ensureKernelShutdown();

        self::assertSame(200, $this->callAs('user', 'GET', '/api/notifications'), substr($this->lastBody, 0, 600));
        self::assertSame(1, json_decode($this->lastBody, true)['unread_count']);
        self::assertSame(404, $this->callAs('finance', 'POST', '/api/notifications/'.$id.'/read'), 'other users cannot read it');
        self::assertSame(200, $this->callAs('user', 'POST', '/api/notifications/'.$id.'/read'));
        self::assertNotNull(json_decode($this->lastBody, true)['read_at']);

        self::assertSame(200, $this->callAs('finance', 'PUT', '/api/notifications/preferences', '{"enabled":true,"task_lead_days":5,"project_lead_days":7,"escalate_after_days":2}'));
        self::assertSame(5, json_decode($this->lastBody, true)['task_lead_days']);
        self::assertContains($this->callAs('finance', 'PUT', '/api/notifications/preferences', '{"task_lead_days":99}'), [400, 422]);
        self::assertSame(200, $this->callAs('user', 'GET', '/api/notifications/preferences'));
        self::assertSame(1, json_decode($this->lastBody, true)['task_lead_days'], 'defaults apply to users without saved preferences');
    }

    public function test_regular_users_see_who_changed_a_record_but_not_the_values(): void
    {
        $this->callAs('admin', 'POST', '/api/clients', '{"title":"Acme","notes":"n","vat_number":"LV12345678"}');
        $id = json_decode($this->lastBody, true)['id'];
        $this->callAs('admin', 'PATCH', '/api/clients/'.$id, '{"title":"Acme Ltd"}');

        self::assertSame(200, $this->callAs('user', 'GET', '/api/history/client/'.$id));
        $history = json_decode($this->lastBody, true);
        self::assertSame(['update', 'create'], array_column($history, 'action'));
        self::assertSame(['title'], $history[0]['changed_fields']);
        self::assertStringNotContainsString('LV12345678', $this->lastBody);
        self::assertSame(404, $this->callAs('user', 'GET', '/api/history/invoice/1'));
        self::assertSame(401, $this->callAs(null, 'GET', '/api/history/client/'.$id));
    }
}
