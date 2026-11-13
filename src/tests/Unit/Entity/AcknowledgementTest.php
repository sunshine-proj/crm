<?php

namespace App\Tests\Unit\Entity;

use App\Entity\AcknowledgementRecipient;
use App\Entity\AcknowledgementRequest;
use App\Entity\Document;
use App\Entity\DocumentType;
use App\Entity\DocumentVersion;
use App\Entity\OrgUnit;
use App\Entity\StoredFile;
use App\Entity\User;
use App\Service\Dms\AcknowledgementService;
use App\Service\Dms\AcknowledgementViews;
use PHPUnit\Framework\TestCase;

final class AcknowledgementTest extends TestCase
{
    private int $nextId = 1;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-06-01 10:00');
    }

    public function test_a_recipient_moves_from_pending_to_viewed_to_acknowledged_on_the_pinned_version(): void
    {
        $request = $this->request(requireOpen: true, dueOn: '2026-06-05');
        $recipient = new AcknowledgementRecipient($request, $this->user('ann'), null, 'Clerk', AcknowledgementRecipient::SENT, 'boss@example.test');
        self::assertSame(AcknowledgementRecipient::PENDING, $recipient->getStatus());
        $this->expectDomain(fn () => $recipient->acknowledge($this->now), 'Open the document');

        self::assertTrue($recipient->markViewed($this->now));
        self::assertFalse($recipient->markViewed($this->now->modify('+1 hour')), 'Only the first opening counts.');
        self::assertSame(AcknowledgementRecipient::VIEWED, $recipient->getStatus());
        self::assertFalse($recipient->isOverdue(new \DateTimeImmutable('2026-06-05')), 'Due today is not overdue.');
        self::assertTrue($recipient->isOverdue(new \DateTimeImmutable('2026-06-06')));

        $recipient->acknowledge(new \DateTimeImmutable('2026-06-07 09:00'));
        self::assertSame(AcknowledgementRecipient::ACKNOWLEDGED, $recipient->getStatus());
        self::assertSame(hash('sha256', 'v1'), $recipient->getAcknowledgedSha256());
        self::assertTrue($recipient->isLate());
        self::assertFalse($recipient->isOverdue(new \DateTimeImmutable('2026-06-08')));
        $this->expectDomain(fn () => $recipient->acknowledge($this->now), 'already');
        $this->expectDomain(fn () => $recipient->excuse('Leave', 'boss', $this->now));
    }

    public function test_excusing_needs_a_reason_and_can_be_undone(): void
    {
        $request = $this->request(requireOpen: false);
        $recipient = new AcknowledgementRecipient($request, $this->user('bob'), null, null, AcknowledgementRecipient::SENT, 'boss');
        $this->expectInvalid(fn () => $recipient->excuse('  ', 'boss', $this->now));
        $recipient->excuse('Sick leave', 'boss', $this->now);
        self::assertFalse($recipient->isOpen());
        $this->expectDomain(fn () => $recipient->excuse('Again', 'boss', $this->now));
        $recipient->markViewed($this->now);
        self::assertSame(AcknowledgementRecipient::EXCUSED, $recipient->getStatus(), 'Opening does not undo an excuse.');
        $recipient->reinstate();
        self::assertSame(AcknowledgementRecipient::VIEWED, $recipient->getStatus(), 'Back to where they were.');
        self::assertNull($recipient->getExcuseReason());
        $this->expectDomain(fn () => $recipient->reinstate());
        $recipient->acknowledge($this->now);
        self::assertFalse($recipient->isLate(), 'No deadline, never late.');
    }

    public function test_request_deadline_targets_and_closing(): void
    {
        $request = $this->request(requireOpen: true, dueOn: '2026-06-05');
        $today = new \DateTimeImmutable('2026-06-03');
        $this->expectInvalid(fn () => $request->extendTo(new \DateTimeImmutable('2026-06-05'), $today), 'extended beyond');
        $this->expectInvalid(fn () => $request->extendTo(new \DateTimeImmutable('2026-06-02'), $today));
        $request->extendTo(new \DateTimeImmutable('2026-06-10'), $today);
        self::assertSame('2026-06-10', $request->getDueOn()?->format('Y-m-d'));

        $request->addTargets([['kind' => 'unit', 'id' => 7, 'include_subunits' => true, 'label' => 'Sales (with sub-units)'], ['kind' => 'user', 'id' => 3, 'include_subunits' => false, 'label' => 'x']]);
        self::assertCount(2, $request->getTargets(), 'The unit was already targeted without sub-units; it is widened, not repeated.');
        self::assertTrue($request->getTargets()[0]['include_subunits']);
        $this->expectInvalid(fn () => $request->addTargets([['kind' => 'team', 'id' => 1, 'include_subunits' => false, 'label' => 'x']]));
        $this->expectInvalid(fn () => $request->addTargets(array_map(static fn (int $id): array => ['kind' => 'user', 'id' => $id + 100, 'include_subunits' => false, 'label' => 'x'], range(1, AcknowledgementRequest::MAX_TARGETS))));
        self::assertCount(2, $request->getTargets(), 'A refused change leaves the targets as they were.');

        $recipient = new AcknowledgementRecipient($request, $this->user('cy'), null, null, AcknowledgementRecipient::SENT, 'boss');
        $request->close('boss', $this->now);
        self::assertFalse($request->isOpen());
        self::assertFalse($recipient->isOverdue(new \DateTimeImmutable('2026-07-01')), 'Closed requests are not overdue.');
        $this->expectDomain(fn () => $recipient->acknowledge($this->now), 'closed');
        $this->expectDomain(fn () => $request->close('boss', $this->now));
        $this->expectDomain(fn () => $request->extendTo(new \DateTimeImmutable('2026-08-01'), $today));
    }

    public function test_counts_and_the_unit_tree_roll_up_and_skip_empty_top_units(): void
    {
        $acme = $this->unit('ACME', 'Acme', null);
        $sales = $this->unit('SALES', 'Sales', $acme);
        $east = $this->unit('EAST', 'East', $sales);
        $west = $this->unit('WEST', 'West', $sales);
        $request = $this->request(requireOpen: false, dueOn: '2026-06-05');
        $rows = [];
        foreach ([['a', $east], ['b', $east], ['c', $west], ['d', $west], ['e', null]] as [$name, $unit]) {
            $rows[$name] = new AcknowledgementRecipient($request, $this->user($name), $unit, null, AcknowledgementRecipient::SENT, 'boss');
        }
        $rows['a']->acknowledge($this->now);
        $rows['c']->acknowledge($this->now);
        $rows['d']->excuse('Leave', 'boss', $this->now);
        $today = new \DateTimeImmutable('2026-06-10');

        $counts = AcknowledgementViews::counts(array_values($rows), $today);
        self::assertSame(['total' => 5, 'pending' => 2, 'viewed' => 0, 'acknowledged' => 2, 'excused' => 1, 'open' => 2, 'overdue' => 2, 'late' => 0, 'completion_percent' => 50], $counts);
        self::assertSame(0, AcknowledgementViews::counts([], $today)['completion_percent']);
        self::assertSame(100, AcknowledgementViews::counts([$rows['d']], $today)['completion_percent'], 'Only excused people: nothing is missing.');

        $views = new AcknowledgementViews((new \ReflectionClass(AcknowledgementService::class))->newInstanceWithoutConstructor());
        $tree = $views->unitTree(array_values($rows), null, $today);
        self::assertSame(
            [['Sales', 0, 4, 0], ['East', 1, 2, 2], ['West', 1, 2, 2], ['No unit recorded', 0, 1, 1]],
            array_map(static fn (array $row): array => [$row['name'], $row['depth'], $row['total']['total'], $row['own']['total']], $tree),
            'Acme passes totals through and is skipped; Sales has two children, so it stays.',
        );
        self::assertSame(50, $tree[1]['total']['completion_percent']);
        self::assertSame(100, $tree[2]['total']['completion_percent']);

        $scoped = $views->unitTree([$rows['a'], $rows['b']], [(int) $east->getId()], $today);
        self::assertSame([['East', 0, null]], array_map(static fn (array $row): array => [$row['name'], $row['depth'], $row['parent_id']], $scoped), 'A manager sees nothing above their units.');
    }

    private function request(bool $requireOpen, ?string $dueOn = null): AcknowledgementRequest
    {
        $document = new Document(new DocumentType('POL', 'Policy'), $this->user('author'), 'Policy');
        $version = new DocumentVersion($document, $document->addVersion(), new StoredFile('stored', 'policy.txt', 'text/plain', 2, hash('sha256', 'v1')), $this->user('author'), null, 'v1');

        return new AcknowledgementRequest($document, $version, $this->user('boss'), [['kind' => 'unit', 'id' => 7, 'include_subunits' => false, 'label' => 'Sales']], ' ', $dueOn !== null ? new \DateTimeImmutable($dueOn) : null, $requireOpen, true);
    }

    private function unit(string $code, string $name, ?OrgUnit $parent): OrgUnit
    {
        $unit = new OrgUnit($code, $name, $parent === null ? 'organization' : 'unit', $parent);
        (new \ReflectionProperty($unit, 'id'))->setValue($unit, $this->nextId++);

        return $unit;
    }

    private function user(string $name): User
    {
        $user = (new User())->setEmail($name.'@example.test');
        (new \ReflectionProperty($user, 'id'))->setValue($user, $this->nextId++);

        return $user;
    }

    private function expectDomain(callable $action, string $contains = ''): void
    {
        try {
            $action();
            self::fail('Expected a DomainException.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString($contains, $exception->getMessage());
        }
    }

    private function expectInvalid(callable $action, string $contains = ''): void
    {
        try {
            $action();
            self::fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString($contains, $exception->getMessage());
        }
    }
}
