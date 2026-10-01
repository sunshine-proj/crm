<?php

namespace App\Tests\Integration\Repository;

use App\Entity\Client;
use App\Entity\ClientContact;
use App\Entity\Project;
use App\Entity\ProjectStatus;
use App\Entity\Task;
use App\Entity\TaskStatus;
use App\Repository\ClientRepository;
use App\Repository\ProjectRepository;
use App\Repository\TaskRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BusinessRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        putenv('DATABASE_URL=sqlite:///:memory:');
        $_ENV['DATABASE_URL'] = 'sqlite:///:memory:';
        $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';

        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->createSchema($metadata);
    }

    protected function tearDown(): void
    {
        $this->entityManager->close();
        parent::tearDown();
    }

    public function test_linked_records_persist_and_dashboard_counts_reflect_status(): void
    {
        $now = new DateTimeImmutable();
        $client = (new Client())
            ->setTitle('Acme')
            ->setNotes('Primary account')
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $contact = (new ClientContact())
            ->setClient($client)
            ->setName('Morgan Lee')
            ->setEmail('morgan@example.com')
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $project = (new Project())
            ->setTitle('Onboarding')
            ->setDescription('Customer implementation')
            ->setClient($client)
            ->setStatus(ProjectStatus::Active)
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $openTask = (new Task())
            ->setTitle('Kickoff')
            ->setDescription('Schedule the kickoff')
            ->setProject($project)
            ->setStatus(TaskStatus::InProgress)
            ->setSchedule($now, $now->modify('+1 hour'))
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $completedTask = (new Task())
            ->setTitle('Prepare documents')
            ->setDescription('Prepare the welcome pack')
            ->setProject($project)
            ->setStatus(TaskStatus::Done)
            ->setSchedule($now, $now->modify('+2 hours'))
            ->setCreatedAt($now)
            ->setUpdatedAt($now);

        $this->entityManager->persist($client);
        $this->entityManager->persist($contact);
        $this->entityManager->persist($project);
        $this->entityManager->persist($openTask);
        $this->entityManager->persist($completedTask);
        $this->entityManager->flush();
        $this->entityManager->clear();

        self::assertInstanceOf(ClientRepository::class, $this->entityManager->getRepository(Client::class));
        self::assertSame(1, $this->entityManager->getRepository(Client::class)->count([]));
        $storedContact = $this->entityManager->getRepository(ClientContact::class)->find($contact->getId());
        self::assertSame('Acme', $storedContact?->getClient()?->getTitle());
        self::assertSame('Morgan Lee', $storedContact?->getName());
        self::assertSame(1, $this->entityManager->getRepository(Project::class)->countWithStatus(ProjectStatus::Active));
        self::assertSame(1, $this->entityManager->getRepository(Task::class)->countOpen());

        $storedTask = $this->entityManager->getRepository(Task::class)->find($openTask->getId());
        self::assertSame('Onboarding', $storedTask?->getProject()?->getTitle());
        self::assertSame('Acme', $storedTask?->getProject()?->getClient()?->getTitle());
    }
}
