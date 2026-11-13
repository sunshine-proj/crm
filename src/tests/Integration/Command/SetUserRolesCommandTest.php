<?php

namespace App\Tests\Integration\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class SetUserRolesCommandTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private CommandTester $command;

    protected function setUp(): void
    {
        putenv('DATABASE_URL=sqlite:///:memory:');
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        (new SchemaTool($this->entityManager))->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());
        $this->entityManager->persist((new User())->setEmail('ann@example.test')->setPasswordHash('x'));
        $this->entityManager->flush();
        $this->command = new CommandTester((new Application(self::$kernel))->find('app:user:roles'));
    }

    protected function tearDown(): void
    {
        $this->entityManager->close();
        parent::tearDown();
    }

    public function test_roles_are_assigned_normalised_and_removed(): void
    {
        self::assertSame(Command::SUCCESS, $this->command->execute(['email' => 'ann@example.test', 'roles' => ['role_finance', 'ROLE_INVENTORY', 'ROLE_FINANCE']]));
        self::assertStringContainsString('must sign in again', $this->command->getDisplay());
        $roles = $this->user()->getRoles();
        self::assertContains('ROLE_FINANCE', $roles);
        self::assertContains('ROLE_INVENTORY', $roles);
        self::assertNotContains('ROLE_ADMIN', $roles);

        self::assertSame(Command::SUCCESS, $this->command->execute(['email' => 'ann@example.test']));
        self::assertSame(['ROLE_USER'], array_values($this->user()->getRoles()));
    }

    public function test_unknown_roles_and_unknown_users_are_refused(): void
    {
        self::assertSame(Command::FAILURE, $this->command->execute(['email' => 'ann@example.test', 'roles' => ['ROLE_SUPERUSER']]));
        self::assertStringContainsString('Unsupported role', $this->command->getDisplay());
        self::assertSame(['ROLE_USER'], array_values($this->user()->getRoles()));

        self::assertSame(Command::FAILURE, $this->command->execute(['email' => 'nobody@example.test', 'roles' => ['ROLE_ADMIN']]));
        self::assertStringContainsString('User not found', $this->command->getDisplay());
    }

    private function user(): User
    {
        $this->entityManager->clear();

        return $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'ann@example.test']);
    }
}
