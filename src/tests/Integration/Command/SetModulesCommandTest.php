<?php

namespace App\Tests\Integration\Command;

use App\Service\Module\ModuleGate;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class SetModulesCommandTest extends KernelTestCase
{
    private CommandTester $tester;

    protected function setUp(): void
    {
        putenv('DATABASE_URL=sqlite:///:memory:');
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        $kernel = self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());
        $this->tester = new CommandTester((new Application($kernel))->find('app:modules:set'));
    }

    public function test_a_preset_then_single_modules_are_applied_with_the_dependency_rules(): void
    {
        self::assertSame(0, $this->tester->execute(['--preset' => 'projects_invoicing', '--enable' => ['sales'], '--disable' => ['news']]));
        $gate = self::getContainer()->get(ModuleGate::class);
        $gate->reset();
        self::assertTrue($gate->isEnabled('sales'));
        self::assertFalse($gate->isEnabled('news'));
        self::assertFalse($gate->isEnabled('accounting'));
        self::assertStringContainsString('everyone', $this->tester->getDisplay());

        self::assertSame(1, $this->tester->execute(['--disable' => ['projects']]));
        self::assertStringContainsString('Turn off Time tracking, Project expenses first', $this->tester->getDisplay());
        self::assertSame(1, $this->tester->execute(['--preset' => 'nope']));
    }
}
