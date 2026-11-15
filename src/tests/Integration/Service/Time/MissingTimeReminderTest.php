<?php

namespace App\Tests\Integration\Service\Time;

use App\Entity\Client;
use App\Entity\Notification;
use App\Entity\Project;
use App\Entity\ReminderPreference;
use App\Entity\TimeEntry;
use App\Entity\User;
use App\Repository\NotificationRepository;
use App\Service\Time\MissingTimeReminder;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MissingTimeReminderTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private MissingTimeReminder $reminder;
    private Project $project;

    protected function setUp(): void
    {
        putenv('DATABASE_URL=sqlite:///:memory:');
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->reminder = self::getContainer()->get(MissingTimeReminder::class);
        (new SchemaTool($this->entityManager))->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());
        $client = (new Client())->setTitle('Acme')->setNotes('n')->setCreatedAt(new \DateTimeImmutable())->setUpdatedAt(new \DateTimeImmutable());
        $this->project = (new Project())->setTitle('Site')->setDescription('d')->setClient($client)->setCreatedAt(new \DateTimeImmutable())->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->persist($client);
        $this->entityManager->persist($this->project);
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        $this->entityManager->close();
        parent::tearDown();
    }

    private function user(string $name): User
    {
        $user = (new User())->setEmail($name.'@example.test')->setPasswordHash('x');
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function logTime(User $user, string $date): void
    {
        $this->entityManager->persist(new TimeEntry($this->project, null, $user, new \DateTimeImmutable($date), 60, false, 'Work'));
        $this->entityManager->flush();
    }

    /** @return list<string> */
    private function inbox(User $user): array
    {
        return array_map(static fn (Notification $n): string => $n->getKind().': '.$n->getMessage(), self::getContainer()->get(NotificationRepository::class)->inbox($user, false, 10));
    }

    public function test_previous_working_day_skips_the_weekend(): void
    {
        self::assertSame('2026-11-13', MissingTimeReminder::previousWorkingDay(new \DateTimeImmutable('2026-11-16'))->format('Y-m-d'), 'Monday checks Friday');
        self::assertSame('2026-11-13', MissingTimeReminder::previousWorkingDay(new \DateTimeImmutable('2026-11-15'))->format('Y-m-d'), 'Sunday checks Friday');
        self::assertSame('2026-11-10', MissingTimeReminder::previousWorkingDay(new \DateTimeImmutable('2026-11-11'))->format('Y-m-d'));
    }

    public function test_people_who_usually_log_time_are_reminded_once_about_a_day_without_any(): void
    {
        $forgetful = $this->user('forgetful');
        $diligent = $this->user('diligent');
        $occasional = $this->user('occasional');
        $optedOut = $this->user('optedout');
        $this->logTime($forgetful, '2026-11-02');
        $this->logTime($diligent, '2026-11-02');
        $this->logTime($diligent, '2026-11-13');
        $this->logTime($occasional, '2026-09-01');
        $this->logTime($optedOut, '2026-11-02');
        $this->entityManager->persist((new ReminderPreference($optedOut))->configure(false, 1, 3, 3));
        $this->entityManager->flush();

        self::assertSame(1, $this->reminder->run(new \DateTimeImmutable('2026-11-16 08:00')));
        self::assertSame(['missing_time: No time logged for Friday (2026-11-13). Add it in your timesheet.'], $this->inbox($forgetful));
        self::assertSame([], $this->inbox($diligent), 'time was logged that day');
        self::assertSame([], $this->inbox($occasional), 'no time in the four weeks before');
        self::assertSame([], $this->inbox($optedOut), 'reminders turned off');
        self::assertSame(0, $this->reminder->run(new \DateTimeImmutable('2026-11-16 18:00')), 'a second run does not repeat the reminder');
    }
}
