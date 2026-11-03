<?php

namespace App\Tests\Integration\Service;

use App\Entity\RecordRevision;
use App\Entity\Warehouse;
use App\Repository\RecordRevisionRepository;
use App\Service\RecordRecovery;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RecordRecoveryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private RecordRevisionRepository $revisions;
    private RecordRecovery $recovery;

    protected function setUp(): void
    {
        putenv('DATABASE_URL=sqlite:///:memory:');
        $_ENV['DATABASE_URL'] = 'sqlite:///:memory:';
        $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';

        self::bootKernel();
        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->revisions = $container->get(RecordRevisionRepository::class);
        $this->recovery = $container->get(RecordRecovery::class);
        (new SchemaTool($this->entityManager))->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());
    }

    protected function tearDown(): void
    {
        $this->entityManager->close();
        parent::tearDown();
    }

    public function test_create_update_and_delete_are_recorded_append_only(): void
    {
        $warehouse = new Warehouse('MAIN', 'Main', null);
        $this->entityManager->persist($warehouse);
        $this->entityManager->flush();
        $id = (int) $warehouse->getId();

        $warehouse->update('MAIN', 'Main warehouse', 'Riga', true);
        $this->entityManager->flush();
        $this->entityManager->remove($warehouse);
        $this->entityManager->flush();

        $history = array_reverse($this->revisions->search('warehouse', $id, null, 10));
        self::assertSame(['create', 'update', 'delete'], array_map(static fn (RecordRevision $r): string => $r->getAction(), $history));
        self::assertSame('Main', $history[0]->getSnapshot()['name']);
        self::assertSame(['Main', 'Main warehouse'], $history[1]->getChanges()['name']);
        self::assertSame('Main warehouse', $history[2]->getSnapshot()['name']);
        self::assertSame('system', $history[0]->getActor());
    }

    public function test_an_update_can_be_rolled_back_to_an_earlier_revision(): void
    {
        $warehouse = new Warehouse('MAIN', 'Main', null);
        $this->entityManager->persist($warehouse);
        $this->entityManager->flush();
        $id = (int) $warehouse->getId();
        $warehouse->update('MAIN', 'Renamed by mistake', null, false);
        $this->entityManager->flush();

        $created = $this->revisions->search('warehouse', $id, 'create', 1)[0];
        $result = $this->recovery->restore($created);

        self::assertFalse($result['recreated']);
        self::assertSame('Main', $warehouse->getName());
        self::assertTrue($warehouse->isActive());
        self::assertCount(3, $this->revisions->search('warehouse', $id, null, 10), 'restoring is itself versioned');
    }

    public function test_a_deleted_record_is_recreated_from_its_delete_revision(): void
    {
        $warehouse = new Warehouse('OLD', 'Old site', 'Tallinn');
        $this->entityManager->persist($warehouse);
        $this->entityManager->flush();
        $id = (int) $warehouse->getId();
        $this->entityManager->remove($warehouse);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $result = $this->recovery->restore($this->revisions->search('warehouse', $id, 'delete', 1)[0]);

        self::assertTrue($result['recreated']);
        $restored = $this->entityManager->getRepository(Warehouse::class)->findOneBy(['code' => 'OLD']);
        self::assertInstanceOf(Warehouse::class, $restored);
        self::assertSame('Old site', $restored->getName());
        self::assertSame('Tallinn', $restored->getAddress());
    }

    public function test_a_missing_record_cannot_be_restored_from_a_non_delete_revision(): void
    {
        $warehouse = new Warehouse('GONE', 'Gone', null);
        $this->entityManager->persist($warehouse);
        $this->entityManager->flush();
        $id = (int) $warehouse->getId();
        $this->entityManager->remove($warehouse);
        $this->entityManager->flush();

        $this->expectException(\DomainException::class);
        $this->recovery->restore($this->revisions->search('warehouse', $id, 'create', 1)[0]);
    }
}
