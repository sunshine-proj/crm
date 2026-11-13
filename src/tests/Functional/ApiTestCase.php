<?php

namespace App\Tests\Functional;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Shared HTTP test harness: a file-backed SQLite database plus one user per role (user, finance, inventory, admin) a buyer holding finance and inventory, and a second regular user (manager). */
abstract class ApiTestCase extends WebTestCase
{
    protected KernelBrowser $client;
    protected string $databaseFile;
    protected string $lastBody = '';
    /** @var array<string, User> */
    protected array $users = [];

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
        foreach (['user' => [], 'finance' => ['ROLE_FINANCE'], 'inventory' => ['ROLE_INVENTORY'], 'buyer' => ['ROLE_FINANCE', 'ROLE_INVENTORY'], 'manager' => [], 'admin' => ['ROLE_ADMIN']] as $name => $roles) {
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

    protected function callAs(?string $as, string $method, string $uri, string $body = '{}'): int
    {
        // The test token only survives the first request of a booted kernel, so use a fresh client per call.
        static::ensureKernelShutdown();
        $this->client = static::createClient();
        if ($as !== null) {
            // Use the user as loaded by this kernel's entity manager, as the JWT user provider would.
            $managed = static::getContainer()->get(EntityManagerInterface::class)->find(User::class, $this->users[$as]->getId());
            $this->client->loginUser($managed, 'api');
        }
        $this->client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json'], content: $body);
        $this->lastBody = (string) $this->client->getResponse()->getContent();
        if (str_starts_with($this->lastBody, '<!--')) {
            // Symfony's HTML error page: keep only the leading comment, which carries the exception message.
            $this->lastBody = html_entity_decode((string) strtok($this->lastBody, "\n"));
        }

        return $this->client->getResponse()->getStatusCode();
    }

    /** @return array<mixed> the last response body decoded as JSON */
    protected function json(): array
    {
        return json_decode($this->lastBody, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Opens fiscal year 2026 and maps one ledger account per posting role.
     *
     * @param array<string, array{0: string, 1: string}> $roles posting role => [account code, account type]
     * @return array<string, int> account ids by posting role
     */
    protected function openLedger(array $roles): array
    {
        self::assertSame(201, $this->callAs('admin', 'POST', '/api/finance/periods', '{"name":"2026","starts_on":"2026-01-01","ends_on":"2026-12-31"}'), $this->lastBody);
        $ids = [];
        foreach ($roles as $role => [$code, $type]) {
            self::assertSame(201, $this->callAs('admin', 'POST', '/api/finance/accounts', json_encode(['code' => $code, 'name' => $role, 'type' => $type])), $this->lastBody);
            $ids[$role] = $this->json()['id'];
        }
        self::assertSame(200, $this->callAs('admin', 'PUT', '/api/finance/account-mappings', json_encode($ids)), $this->lastBody);

        return $ids;
    }

    /** @param array<string, mixed> $fields extra project fields */
    protected function createClientAndProject(array $fields = []): array
    {
        self::assertSame(201, $this->callAs('admin', 'POST', '/api/clients', '{"title":"Acme","notes":"n"}'), $this->lastBody);
        $clientId = $this->json()['id'];
        $body = ['title' => 'Build', 'description' => 'd', 'client_id' => $clientId, 'status' => 'active'] + $fields;
        self::assertSame(201, $this->callAs('user', 'POST', '/api/projects', json_encode($body)), $this->lastBody);

        return ['client' => $clientId, 'project' => $this->json()['id']];
    }

    /**
     * Audit-trail records written while handling the last request.
     *
     * @return list<array{message: string, level: string, context: array<string, mixed>}>
     */
    protected function auditRecords(): array
    {
        return array_map(
            static fn ($record): array => ['message' => $record['message'], 'level' => strtolower($record['level_name']), 'context' => $record['context']],
            static::getContainer()->get('monolog.handler.audit_test')->getRecords(),
        );
    }

    /** Invalid input is refused either by a controller check (400) or by request-payload validation (422). */
    protected static function assertInvalid(int $status, string $message = ''): void
    {
        self::assertContains($status, [400, 422], $message);
    }
}
