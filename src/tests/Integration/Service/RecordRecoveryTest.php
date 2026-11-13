<?php

namespace App\Tests\Integration\Service;

use App\Entity\Client;
use App\Entity\ClientContact;
use App\Entity\Deal;
use App\Entity\Project;
use App\Entity\ProjectMilestone;
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

    public function test_records_removed_or_detached_by_a_database_cascade_are_versioned_with_their_cause(): void
    {
        [$clientId, $projectId] = $this->deleteClientWithRelatedRecords();

        $caused = $this->revisions->findCausedBy('client', $clientId);
        $summary = array_map(static fn (RecordRevision $r): string => $r->getEntityType().':'.$r->getAction(), $caused);
        sort($summary);
        self::assertSame(['client_contact:delete', 'deal:delete', 'project:update'], $summary);
        $detached = array_values(array_filter($caused, static fn (RecordRevision $r): bool => $r->getEntityType() === 'project'))[0];
        self::assertSame([$clientId, null], $detached->getChanges()['client']);
        self::assertNull($detached->getSnapshot()['client']);
        self::assertSame($projectId, $detached->getEntityId());
        self::assertNull($this->revisions->search('client', $clientId, 'delete', 1)[0]->getCauseType());
    }

    public function test_recreating_a_deleted_client_brings_back_its_contacts_and_deals_and_relinks_its_projects(): void
    {
        [$clientId, $projectId] = $this->deleteClientWithRelatedRecords();

        $result = $this->recovery->restore($this->revisions->search('client', $clientId, 'delete', 1)[0]);

        self::assertTrue($result['recreated']);
        self::assertSame(3, $result['related']);
        $client = $result['entity'];
        self::assertInstanceOf(Client::class, $client);
        self::assertNotSame($clientId, $client->getId());
        $this->entityManager->clear();
        $contact = $this->entityManager->getRepository(ClientContact::class)->findOneBy(['email' => 'ann@acme.test']);
        self::assertSame($client->getId(), $contact?->getClient()?->getId());
        $deal = $this->entityManager->getRepository(Deal::class)->findOneBy(['title' => 'Renewal']);
        self::assertSame($client->getId(), $deal?->getClient()->getId());
        self::assertSame($client->getId(), $this->entityManager->find(Project::class, $projectId)->getClient()?->getId());
    }

    public function test_related_records_can_be_left_out_and_a_relinked_project_is_not_overwritten(): void
    {
        [$clientId, $projectId] = $this->deleteClientWithRelatedRecords();
        $other = (new Client())->setTitle('Other')->setNotes('n')->setCreatedAt($this->now())->setUpdatedAt($this->now());
        $this->entityManager->persist($other);
        $this->entityManager->find(Project::class, $projectId)->setClient($other);
        $this->entityManager->flush();
        $revision = $this->revisions->search('client', $clientId, 'delete', 1)[0];

        self::assertSame(0, $this->recovery->restore($revision, false)['related']);
        self::assertNull($this->entityManager->getRepository(Deal::class)->findOneBy(['title' => 'Renewal']));

        self::assertSame(2, $this->recovery->restore($revision)['related'], 'the project now belongs to another client and stays there');
        self::assertSame('Other', $this->entityManager->find(Project::class, $projectId)->getClient()?->getTitle());
    }

    public function test_deleting_a_project_versions_its_milestones_so_they_return_with_it(): void
    {
        $project = (new Project())->setTitle('Build')->setDescription('d')->setCreatedAt($this->now())->setUpdatedAt($this->now());
        $this->entityManager->persist($project);
        $this->entityManager->persist(new ProjectMilestone($project, 'Design', 'd', null, 5000, 'EUR'));
        $this->entityManager->flush();
        $projectId = (int) $project->getId();
        $this->entityManager->remove($project);
        $this->entityManager->flush();
        $this->entityManager->createQuery('DELETE FROM '.ProjectMilestone::class)->execute();
        $this->entityManager->clear();

        $result = $this->recovery->restore($this->revisions->search('project', $projectId, 'delete', 1)[0]);

        self::assertSame(1, $result['related']);
        $milestone = $this->entityManager->getRepository(ProjectMilestone::class)->findOneBy(['title' => 'Design']);
        self::assertSame($result['entity']->getId(), $milestone?->getProject()->getId());
        self::assertSame(5000, $milestone->getAmountMinor());
    }

    /** @return array{0: int, 1: int} deleted client id and the id of the project that belonged to it */
    private function deleteClientWithRelatedRecords(): array
    {
        $client = (new Client())->setTitle('Acme')->setNotes('n')->setCreatedAt($this->now())->setUpdatedAt($this->now());
        $contact = (new ClientContact())->setClient($client)->setName('Ann')->setEmail('ann@acme.test')->setCreatedAt($this->now())->setUpdatedAt($this->now());
        $deal = new Deal($client, 'Renewal');
        $project = (new Project())->setTitle('Build')->setDescription('d')->setClient($client)->setCreatedAt($this->now())->setUpdatedAt($this->now());
        foreach ([$client, $contact, $deal, $project] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
        $ids = [(int) $client->getId(), (int) $project->getId()];

        $this->entityManager->remove($client);
        $this->entityManager->flush();
        // SQLite does not enforce the foreign-key rules PostgreSQL applies, so mirror them here.
        $this->entityManager->createQuery('DELETE FROM '.ClientContact::class)->execute();
        $this->entityManager->createQuery('DELETE FROM '.Deal::class)->execute();
        $this->entityManager->createQuery('UPDATE '.Project::class.' p SET p.client = NULL')->execute();
        $this->entityManager->clear();

        return $ids;
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-01 10:00:00');
    }
}
