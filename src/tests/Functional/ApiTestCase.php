<?php

namespace App\Tests\Functional;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Shared HTTP test harness: a file-backed SQLite database plus one user per role (user, finance, admin). */
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

    protected function callAs(?string $as, string $method, string $uri, string $body = '{}'): int
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

    /** @return array<mixed> the last response body decoded as JSON */
    protected function json(): array
    {
        return json_decode($this->lastBody, true, 512, JSON_THROW_ON_ERROR);
    }
}
