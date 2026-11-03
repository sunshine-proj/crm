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
}
