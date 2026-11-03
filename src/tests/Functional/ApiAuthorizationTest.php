<?php

namespace App\Tests\Functional;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Exercises real HTTP routing, firewall and role checks (allowed and denied) for the role model. */
final class ApiAuthorizationTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $databaseFile;
    private string $lastBody = '';
    /** @var array<string, User> */
    private array $users = [];

    protected function setUp(): void
    {
        // A file database survives the kernel reboot that happens between browser sessions.
        $this->databaseFile = sys_get_temp_dir().'/crm-authz-'.getmypid().'.sqlite';
        @unlink($this->databaseFile);
        putenv('DATABASE_URL=sqlite:///'.$this->databaseFile);
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///'.$this->databaseFile;

        $this->client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());
        foreach (['user' => [], 'finance' => ['ROLE_FINANCE'], 'admin' => ['ROLE_ADMIN']] as $name => $roles) {
            $user = (new User())->setEmail($name.'@example.test')->setPasswordHash('x')->setRoles($roles);
            $entityManager->persist($user);
            $this->users[$name] = $user;
        }
        $entityManager->flush();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        @unlink($this->databaseFile);
    }

    private function callAs(?string $as, string $method, string $uri, string $body = '{}'): int
    {
        // The test token only survives the first request of a booted kernel, so use a fresh client per call.
        static::ensureKernelShutdown();
        $this->client = static::createClient();
        if ($as !== null) {
            $this->client->loginUser($this->users[$as], 'api');
        }
        $this->client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json'], content: $body);
        $this->lastBody = (string) $this->client->getResponse()->getContent();

        return $this->client->getResponse()->getStatusCode();
    }

    public function test_anonymous_requests_are_rejected(): void
    {
        self::assertSame(401, $this->callAs(null, 'GET', '/api/clients'));
        self::assertSame(401, $this->callAs(null, 'GET', '/api/finance/currencies'));
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
