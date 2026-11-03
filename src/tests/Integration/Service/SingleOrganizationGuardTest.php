<?php

namespace App\Tests\Integration\Service;

use App\Entity\OrganizationSettings;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * ORG-4: an installation serves exactly one organization until tenant ownership and
 * per-organization authorization exist. The settings row is pinned to id 1.
 */
final class SingleOrganizationGuardTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        putenv('DATABASE_URL=sqlite:///:memory:');
        $_ENV['DATABASE_URL'] = 'sqlite:///:memory:';
        $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';

        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        (new SchemaTool($this->entityManager))->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());
    }

    protected function tearDown(): void
    {
        $this->entityManager->close();
        parent::tearDown();
    }

    public function test_a_second_organization_settings_row_cannot_be_stored(): void
    {
        $this->entityManager->persist(new OrganizationSettings());
        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->entityManager->persist(new OrganizationSettings());

        $this->expectException(UniqueConstraintViolationException::class);
        $this->entityManager->flush();
    }
}
