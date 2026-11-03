<?php

namespace App\Tests\Integration\Service;

use App\Entity\Client;
use App\Entity\Notification;
use App\Entity\Project;
use App\Entity\ReminderPreference;
use App\Entity\Task;
use App\Entity\TaskStatus;
use App\Entity\User;
use App\Repository\NotificationRepository;
use App\Service\ReminderGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ReminderGeneratorTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private ReminderGenerator $generator;
    private User $assignee;
    private User $manager;
    private User $admin;
    private Project $project;

    protected function setUp(): void
    {
        putenv('DATABASE_URL=sqlite:///:memory:');
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->generator = self::getContainer()->get(ReminderGenerator::class);
        (new SchemaTool($this->entityManager))->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());

        $this->assignee = (new User())->setEmail('dev@example.test')->setPasswordHash('x');
        $this->manager = (new User())->setEmail('pm@example.test')->setPasswordHash('x');
        $this->admin = (new User())->setEmail('admin@example.test')->setPasswordHash('x')->setRoles(['ROLE_ADMIN']);
        $client = (new Client())->setTitle('Acme')->setNotes('n')->setCreatedAt(new \DateTimeImmutable())->setUpdatedAt(new \DateTimeImmutable());
        $this->project = (new Project())->setTitle('Site')->setDescription('d')->setClient($client)->setManager($this->manager)->setCreatedAt(new \DateTimeImmutable())->setUpdatedAt(new \DateTimeImmutable());
        foreach ([$this->assignee, $this->manager, $this->admin, $client, $this->project] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        $this->entityManager->close();
        parent::tearDown();
    }

    private function task(string $due, TaskStatus $status = TaskStatus::Todo, ?User $assignee = null): Task
    {
        $task = (new Task())->setTitle('Build')->setDescription('d')->setProject($this->project)->setAssignee($assignee ?? $this->assignee)
            ->setStatus($status)->setDueDate(new \DateTimeImmutable($due))->setSchedule(new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-12-31'))->setCreatedAt(new \DateTimeImmutable())->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->persist($task);
        $this->entityManager->flush();

        return $task;
    }

    /** @return list<string> */
    private function messagesFor(User $user): array
    {
        return array_map(static fn (Notification $n): string => $n->getKind().': '.$n->getMessage(), self::getContainer()->get(NotificationRepository::class)->inbox($user, false, 50));
    }

    public function test_a_task_due_tomorrow_reminds_its_assignee_once(): void
    {
        $this->task('2026-11-04');
        $now = new \DateTimeImmutable('2026-11-03 09:00');

        self::assertSame(1, $this->generator->run($now)['due_soon']);
        self::assertSame(0, $this->generator->run($now)['due_soon'], 'a second run must not repeat the reminder');
        self::assertStringContainsString('due in 1 day', $this->messagesFor($this->assignee)[0]);
    }

    public function test_work_outside_the_lead_window_or_finished_is_ignored(): void
    {
        $this->task('2026-11-20');
        $this->task('2026-11-04', TaskStatus::Done);
        $this->task('2026-11-04', TaskStatus::Cancelled);

        self::assertSame(['due_soon' => 0, 'overdue' => 0, 'escalations' => 0], $this->generator->run(new \DateTimeImmutable('2026-11-03')));
    }

    public function test_lead_time_is_configurable_per_user(): void
    {
        $this->task('2026-11-08');
        $this->entityManager->persist((new ReminderPreference($this->assignee))->configure(true, 7, 3, 3));
        $this->entityManager->flush();

        self::assertSame(1, $this->generator->run(new \DateTimeImmutable('2026-11-03'))['due_soon']);
    }

    public function test_overdue_work_notifies_the_assignee_and_escalates_to_the_project_manager_after_the_threshold(): void
    {
        $this->task('2026-11-01');

        $early = $this->generator->run(new \DateTimeImmutable('2026-11-02'));
        self::assertSame(1, $early['overdue']);
        self::assertSame(0, $early['escalations'], 'one day overdue is below the default 3-day threshold');

        $late = $this->generator->run(new \DateTimeImmutable('2026-11-05'));
        self::assertSame(1, $late['escalations']);
        self::assertStringContainsString('Escalation', $this->messagesFor($this->manager)[0]);
        self::assertSame([], $this->messagesFor($this->admin), 'the manager, not the admin, is the escalation target');
    }

    public function test_escalation_can_be_disabled_and_a_muted_user_gets_no_own_reminders(): void
    {
        $this->task('2026-11-01');
        $this->entityManager->persist((new ReminderPreference($this->assignee))->configure(false, 1, 3, 0));
        $this->entityManager->flush();

        self::assertSame(['due_soon' => 0, 'overdue' => 0, 'escalations' => 0], $this->generator->run(new \DateTimeImmutable('2026-11-10')));
    }

    public function test_when_the_assignee_is_the_manager_overdue_work_escalates_to_administrators(): void
    {
        $this->task('2026-11-01', TaskStatus::InProgress, $this->manager);

        $result = $this->generator->run(new \DateTimeImmutable('2026-11-06'));

        self::assertSame(1, $result['escalations']);
        self::assertStringContainsString('Escalation', $this->messagesFor($this->admin)[0]);
    }

    public function test_a_changed_due_date_creates_a_fresh_reminder(): void
    {
        $task = $this->task('2026-11-04');
        $this->generator->run(new \DateTimeImmutable('2026-11-03'));
        $task->setDueDate(new \DateTimeImmutable('2026-11-10'));
        $this->entityManager->flush();

        self::assertSame(1, $this->generator->run(new \DateTimeImmutable('2026-11-09'))['due_soon']);
    }
}
