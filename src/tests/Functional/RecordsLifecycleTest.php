<?php

namespace App\Tests\Functional;

use App\Entity\ApprovalStep;
use App\Entity\Document;
use App\Entity\Notification;
use App\Entity\User;
use App\Service\Dms\DocumentLifecycle;
use App\Service\ReminderGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Records lifecycle, retention, legal hold, disposal, audit trail and reports.
 * Structure: ACME (head: admin) > SALES (head: manager; staff: user, buyer). "clerk" holds ROLE_DMS_ADMIN.
 * Documents are written by "user" (owner unit Sales) and registered by the clerk.
 */
final class RecordsLifecycleTest extends ApiTestCase
{
    /** @var array<string, int> */
    private array $ids = [];
    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $clerk = (new User())->setEmail('clerk@example.test')->setPasswordHash('x')->setRoles(['ROLE_DMS_ADMIN']);
        $entityManager->persist($clerk);
        $entityManager->flush();
        $this->users['clerk'] = $clerk;
        foreach ([['ACME', 'organization', null], ['SALES', 'unit', 'ACME']] as [$code, $kind, $parent]) {
            $body = ['code' => $code, 'name' => ucfirst(strtolower($code)), 'kind' => $kind] + ($parent ? ['parent_id' => $this->ids[$parent]] : []);
            self::assertSame(201, $this->callAs('admin', 'POST', '/api/org/units', json_encode($body)), $this->lastBody);
            $this->ids[$code] = $this->json()['id'];
        }
        foreach ([['admin', 'ACME', true], ['manager', 'SALES', true], ['user', 'SALES', false], ['buyer', 'SALES', false]] as [$who, $unit, $head]) {
            self::assertSame(201, $this->callAs('admin', 'POST', '/api/org/positions', json_encode(['unit_id' => $this->ids[$unit], 'title' => ucfirst($who), 'is_head' => $head])));
            self::assertSame(201, $this->callAs('admin', 'POST', '/api/org/employments', json_encode(['user_id' => $this->id($who), 'position_id' => $this->json()['id'], 'starts_on' => '2026-01-01'])));
        }
        foreach ([['POL', false, 1], ['ORD', true, null]] as [$code, $approval, $retention]) {
            self::assertSame(201, $this->callAs('clerk', 'POST', '/api/dms/types', json_encode(['code' => $code, 'name' => $code, 'requires_approval' => $approval] + ($retention ? ['retention_years' => $retention] : []))), $this->lastBody);
            $this->ids[$code] = $this->json()['id'];
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function test_documents_take_effect_supersede_their_predecessors_and_expire_into_the_archive(): void
    {
        $tomorrow = (new \DateTimeImmutable('+1 day'))->format('Y-m-d');
        $old = $this->registered('Travel policy 2025');
        self::assertSame(201, $this->callAs('manager', 'POST', "/api/dms/documents/$old/acknowledgements", json_encode(['targets' => [['kind' => 'unit', 'id' => $this->ids['SALES']]]])), $this->lastBody);
        $request = $this->json()['id'];

        // A replacement in force at once supersedes the old document on registration.
        $new = $this->draft('Travel policy 2026');
        self::assertSame(201, $this->callAs('user', 'POST', "/api/dms/documents/$new/links", json_encode(['target_id' => $old, 'kind' => 'replaces'])));
        $this->register($new);
        self::assertSame('superseded', $this->statusOf($old));
        self::assertSame(200, $this->callAs('manager', 'GET', "/api/dms/acknowledgements/$request"));
        self::assertSame('closed', $this->json()['status'], 'Superseded documents are no longer to be acknowledged.');
        self::assertSame(200, $this->callAs('user', 'GET', '/api/notifications'));
        self::assertStringContainsString('"Travel policy 2025" is superseded by', $this->messages());

        // A replacement with a later effective date waits; the lifecycle run puts it in force and supersedes.
        $future = $this->draft('Travel policy 2027', ['effective_on' => $tomorrow, 'expires_on' => $tomorrow]);
        self::assertSame(201, $this->callAs('user', 'POST', "/api/dms/documents/$future/links", json_encode(['target_id' => $new, 'kind' => 'replaces'])));
        $this->register($future);
        self::assertSame('registered', $this->statusOf($future));
        self::assertSame('in_force', $this->statusOf($new));
        $lifecycle = fn (string $when): array => static::getContainer()->get(DocumentLifecycle::class)->run(new \DateTimeImmutable($when));
        self::assertSame(['in_force' => 0, 'superseded' => 0, 'archived' => 0], $lifecycle('now'));
        self::assertSame(['in_force' => 1, 'superseded' => 1, 'archived' => 0], $lifecycle('+1 day'));
        self::assertSame(['in_force' => 'in_force', 'new' => 'superseded'], ['in_force' => $this->statusOf($future), 'new' => $this->statusOf($new)]);
        self::assertSame(['in_force' => 0, 'superseded' => 0, 'archived' => 0], $lifecycle('+1 day'), 'Idempotent.');

        // The day after the expiry date the document is archived; superseded ones too when they expire.
        self::assertSame(['in_force' => 0, 'superseded' => 0, 'archived' => 1], $lifecycle('+2 days'));
        self::assertSame(200, $this->callAs('user', 'GET', "/api/dms/documents/$future"));
        self::assertSame('archived', $this->json()['status']);
        $archived = array_values(array_filter($this->json()['events'], static fn (array $event): bool => $event['action'] === 'archived'));
        self::assertSame(DocumentLifecycle::ACTOR, $archived[0]['actor']);
        self::assertSame('expired '.$tomorrow, $archived[0]['details']['reason']);
        self::assertSame((new \DateTimeImmutable('+1 year'))->format('Y').'-', substr((string) $this->json()['retain_until'], 0, 5), 'POL keeps archived documents for one year.');
    }

    public function test_review_dates_remind_the_owner_escalate_and_are_moved_by_a_review(): void
    {
        $doc = $this->registered('Data policy', ['review_on' => (new \DateTimeImmutable('+3 days'))->format('Y-m-d')]);
        $generator = fn (): ReminderGenerator => static::getContainer()->get(ReminderGenerator::class);
        $generator()->run(new \DateTimeImmutable());
        self::assertSame(200, $this->callAs('user', 'GET', '/api/notifications'));
        self::assertStringContainsString('Review of "Data policy" is due in 3 days', $this->messages());
        $counts = $generator()->run(new \DateTimeImmutable('+10 days'));
        self::assertSame([1, 1], [$counts['overdue'] >= 1 ? 1 : 0, $counts['escalations']]);
        $escalation = static::getContainer()->get(EntityManagerInterface::class)->getRepository(Notification::class)->findOneBy(['kind' => Notification::ESCALATION]);
        self::assertSame('manager@example.test', $escalation?->getUser()->getUserIdentifier(), 'The head of the owner unit.');

        $next = (new \DateTimeImmutable('+1 year'))->format('Y-m-d');
        self::assertSame(403, $this->callAs('buyer', 'POST', "/api/dms/documents/$doc/review", json_encode(['next_review_on' => $next])), 'Only the owner or a clerk.');
        self::assertSame(400, $this->callAs('user', 'POST', "/api/dms/documents/$doc/review", json_encode(['next_review_on' => '2020-01-01'])));
        self::assertSame(200, $this->callAs('user', 'POST', "/api/dms/documents/$doc/review", json_encode(['next_review_on' => $next, 'comment' => 'Still accurate'])), $this->lastBody);
        self::assertSame($next, $this->json()['review_on']);
        self::assertSame(['Still accurate', $next], [end($this->json()['events'])['details']['comment'], end($this->json()['events'])['details']['next_review_on']]);
        self::assertFalse($this->json()['permissions']['can_edit'], 'Registered documents stay frozen; only the review date moved.');
    }

    public function test_legal_hold_and_four_eyes_disposal_leave_a_tombstone(): void
    {
        $doc = $this->registered('Old expense rules', ['summary' => 'Internal summary', 'tags' => ['finance']]);
        $other = $this->registered('Old travel rules');
        foreach ([$doc, $other] as $id) {
            self::assertSame(200, $this->callAs('clerk', 'POST', "/api/dms/documents/$id/archive"));
            $this->archivedYearsAgo($id, 2);
        }
        self::assertSame(200, $this->callAs('clerk', 'GET', '/api/dms/disposals/eligible'));
        self::assertEqualsCanonicalizing([$doc, $other], array_column($this->json(), 'id'));
        self::assertSame(403, $this->callAs('user', 'GET', '/api/dms/disposals/eligible'));

        // Legal hold: clerks only; blocks disposal, and placing it rejects an open proposal.
        self::assertSame(403, $this->callAs('user', 'POST', "/api/dms/documents/$doc/legal-hold", '{"reason":"Case 17"}'));
        self::assertSame(400, $this->callAs('clerk', 'POST', "/api/dms/documents/$doc/legal-hold", '{"reason":" "}'));
        self::assertSame(201, $this->callAs('clerk', 'POST', "/api/dms/documents/$doc/disposal", '{"reason":"Retention schedule 4.2"}'), $this->lastBody);
        self::assertSame(409, $this->callAs('clerk', 'POST', "/api/dms/documents/$doc/disposal", '{"reason":"Again"}'), 'One open proposal.');
        self::assertSame(200, $this->callAs('clerk', 'POST', "/api/dms/documents/$doc/legal-hold", '{"reason":"Audit request 2026/3"}'));
        self::assertSame('Audit request 2026/3', $this->json()['legal_hold']['reason']);
        self::assertSame(['rejected'], array_column($this->json()['disposals'], 'status'), 'The open proposal was rejected by the hold.');
        self::assertSame(409, $this->callAs('clerk', 'POST', "/api/dms/documents/$doc/disposal", '{"reason":"Retention schedule 4.2"}'));
        self::assertStringContainsString('legal hold', $this->lastBody);
        self::assertSame(200, $this->callAs('clerk', 'DELETE', "/api/dms/documents/$doc/legal-hold", '{"comment":"Audit closed"}'));
        self::assertNull($this->json()['legal_hold']);

        // Four eyes: the clerk proposes; the clerk cannot decide; an administrator other than the proposer decides.
        self::assertSame(201, $this->callAs('clerk', 'POST', "/api/dms/documents/$doc/disposal", '{"reason":"Retention schedule 4.2"}'));
        $disposal = $this->json()['id'];
        self::assertSame(200, $this->callAs('admin', 'GET', '/api/notifications'));
        self::assertStringContainsString('proposes disposing of', $this->messages());
        self::assertSame(403, $this->callAs('clerk', 'POST', "/api/dms/disposals/$disposal/approve", '{}'));
        self::assertSame(201, $this->callAs('admin', 'POST', "/api/dms/documents/$other/disposal", '{"reason":"Schedule 4.2"}'));
        $own = $this->json()['id'];
        self::assertSame(409, $this->callAs('admin', 'POST', "/api/dms/disposals/$own/approve", '{}'), 'Not one\'s own proposal.');
        self::assertSame(200, $this->callAs('admin', 'GET', '/api/dms/disposals?status=proposed'));
        self::assertSame([$own => false, $disposal => true], array_column($this->json(), 'can_decide', 'id'));
        self::assertSame(400, $this->callAs('admin', 'POST', "/api/dms/disposals/$disposal/reject", '{"comment":""}'));

        $storage = static::getContainer()->getParameter('app.dms_storage_dir');
        $files = count(glob($storage.'/*') ?: []);
        self::assertSame(200, $this->callAs('admin', 'POST', "/api/dms/disposals/$disposal/approve", '{"comment":"Approved per schedule"}'), $this->lastBody);
        $result = $this->json();
        self::assertSame('approved', $result['status']);
        self::assertSame(hash('sha256', 'Policy text'), $result['manifest']['versions'][0]['sha256']);
        self::assertSame(1, $result['manifest']['retention_years']);
        self::assertSame($files - 1, count(glob($storage.'/*') ?: []), 'The version file is destroyed.');
        self::assertSame(409, $this->callAs('admin', 'POST', "/api/dms/disposals/$disposal/approve", '{}'));

        // The tombstone: number, title, hashes and trail remain; content does not.
        self::assertSame(200, $this->callAs('clerk', 'GET', "/api/dms/documents/$doc"));
        $tombstone = $this->json();
        self::assertSame(['disposed', 'Old expense rules', null, []], [$tombstone['status'], $tombstone['title'], $tombstone['summary'], $tombstone['tags']]);
        self::assertNotNull($tombstone['registration_number']);
        self::assertTrue($tombstone['versions'][0]['file_disposed']);
        self::assertSame(hash('sha256', 'Policy text'), $tombstone['versions'][0]['sha256']);
        self::assertContains('disposed', array_column($tombstone['events'], 'action'));
        self::assertSame(410, $this->callAs('clerk', 'GET', "/api/dms/documents/$doc/versions/1/file"));
        self::assertSame(409, $this->callAs('clerk', 'POST', "/api/dms/documents/$doc/legal-hold", '{"reason":"Too late"}'));
        self::assertSame(409, $this->callAs('clerk', 'DELETE', "/api/dms/documents/$doc"), 'Registered documents are never deleted.');

        self::assertSame(403, $this->callAs('manager', 'POST', "/api/dms/disposals/$own/reject", '{"comment":"x"}'));
        self::assertSame(409, $this->callAs('admin', 'POST', "/api/dms/disposals/$own/reject", '{"comment":"Changed my mind"}'), 'Rejecting is a decision too: someone else decides.');
    }

    public function test_draft_under_legal_hold_cannot_be_deleted(): void
    {
        $draft = $this->draft('Disputed contract');
        self::assertSame(200, $this->callAs('clerk', 'POST', "/api/dms/documents/$draft/legal-hold", '{"reason":"Dispute 9"}'));
        self::assertFalse($this->json()['permissions']['can_delete']);
        self::assertSame(409, $this->callAs('user', 'DELETE', "/api/dms/documents/$draft"));
        self::assertStringContainsString('legal hold', $this->lastBody);
    }

    public function test_the_audit_trail_records_views_access_changes_and_approval_actions(): void
    {
        $doc = $this->draft('Purchase order 9', [], 'ORD');
        self::assertSame(200, $this->callAs('user', 'GET', "/api/dms/documents/$doc"));
        self::assertSame(200, $this->callAs('user', 'GET', "/api/dms/documents/$doc"));
        self::assertSame(200, $this->callAs('user', 'PUT', "/api/dms/documents/$doc/readers", json_encode(['user_ids' => [$this->id('buyer')]])));
        self::assertSame(200, $this->callAs('user', 'PUT', "/api/dms/documents/$doc/readers", json_encode(['user_ids' => [$this->id('finance')]])));
        self::assertSame(201, $this->callAs('user', 'POST', "/api/dms/documents/$doc/approval/submit", json_encode(['stages' => [['steps' => [['assignee_id' => $this->id('manager')]]]]])), $this->lastBody);
        self::assertSame(200, $this->callAs('manager', 'GET', '/api/dms/approvals/inbox'));
        self::assertSame(200, $this->callAs('manager', 'POST', '/api/dms/approvals/steps/'.$this->json()[0]['id'].'/decision', '{"decision":"approve","comment":"Fine"}'));

        self::assertSame(200, $this->callAs('user', 'GET', "/api/dms/documents/$doc/audit"));
        $actions = array_column($this->json()['entries'], 'action');
        self::assertSame(1, count(array_keys($actions, 'viewed', true)), 'Views are recorded once per person and day.');
        self::assertContains('approval_submitted', $actions);
        self::assertContains('approval_approved_with_comments', $actions);
        self::assertSame(200, $this->callAs('user', 'GET', "/api/dms/documents/$doc/audit?action=readers"));
        self::assertSame([[null, 'buyer@example.test'], ['buyer@example.test', 'finance@example.test']], array_map(static fn (array $entry): array => [$entry['details']['removed'], $entry['details']['added']], $this->json()['entries']));
        self::assertSame(200, $this->callAs('user', 'GET', "/api/dms/documents/$doc/audit?actor=MANAGER"));
        self::assertSame([['approval', 'approval_approved_with_comments'], ['document', 'approval_approved']], array_map(static fn (array $entry): array => [$entry['source'], $entry['action']], $this->json()['entries']), 'The decision from the route log, and the outcome on the document.');
        self::assertSame(200, $this->callAs('user', 'GET', "/api/dms/documents/$doc/audit?from=2000-01-01&to=2000-12-31"));
        self::assertSame(0, $this->json()['total']);
        self::assertSame(400, $this->callAs('user', 'GET', "/api/dms/documents/$doc/audit?from=yesterday"));
        self::assertSame(200, $this->callAs('user', 'GET', "/api/dms/documents/$doc/audit?format=csv"));
        self::assertStringContainsString('When,Source,Action,Actor', $this->lastBody);
        self::assertStringContainsString('approval_approved_with_comments,manager@example.test', $this->lastBody);
        self::assertSame(403, $this->callAs('manager', 'GET', "/api/dms/documents/$doc/audit"), 'Participants read the document, not its audit.');
        self::assertSame(200, $this->callAs('clerk', 'GET', "/api/dms/documents/$doc/audit"));
        self::assertSame(404, $this->callAs('inventory', 'GET', "/api/dms/documents/$doc/audit"));
    }

    public function test_reports_for_clerks(): void
    {
        $doc = $this->draft('Order A', [], 'ORD');
        self::assertSame(201, $this->callAs('user', 'POST', "/api/dms/documents/$doc/approval/submit", json_encode(['stages' => [['steps' => [['assignee_id' => $this->id('manager')]]]]])));
        self::assertSame(200, $this->callAs('manager', 'GET', '/api/dms/approvals/inbox'));
        self::assertSame(200, $this->callAs('manager', 'POST', '/api/dms/approvals/steps/'.$this->json()[0]['id'].'/decision', '{"decision":"approve"}'));
        $late = $this->draft('Order B', [], 'ORD');
        self::assertSame(201, $this->callAs('user', 'POST', "/api/dms/documents/$late/approval/submit", json_encode(['stages' => [['steps' => [['assignee_id' => $this->id('manager')]]]]])));
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $step = $entityManager->getRepository(ApprovalStep::class)->findOneBy(['status' => ApprovalStep::PENDING]);
        (new \ReflectionProperty($step, 'dueOn'))->setValue($step, new \DateTimeImmutable('-4 days'));
        $entityManager->flush();
        $policy = $this->registered('Policy A');
        self::assertSame(201, $this->callAs('manager', 'POST', "/api/dms/documents/$policy/acknowledgements", json_encode(['targets' => [['kind' => 'unit', 'id' => $this->ids['SALES']]], 'require_open' => false])));
        self::assertSame(200, $this->callAs('user', 'GET', '/api/dms/acknowledgements/inbox'));
        self::assertSame(200, $this->callAs('user', 'POST', '/api/dms/acknowledgements/recipients/'.$this->json()[0]['id'].'/acknowledge'));

        self::assertSame(403, $this->callAs('manager', 'GET', '/api/dms/reports'));
        self::assertSame(200, $this->callAs('clerk', 'GET', '/api/dms/reports'), $this->lastBody);
        $report = $this->json();
        self::assertSame(['approved' => 1, 'in_approval' => 1, 'in_force' => 1], $report['documents']['by_status']);
        self::assertSame(3, $report['documents']['total']);
        self::assertSame([['kind' => 'approve', 'decided' => 1]], array_map(static fn (array $row): array => ['kind' => $row['kind'], 'decided' => $row['decided']], $report['approval_time']['by_kind']));
        self::assertSame('manager@example.test', $report['approval_time']['by_approver'][0]['approver']);
        self::assertSame([['approver' => 'manager@example.test', 'overdue' => 1, 'oldest_due_on' => (new \DateTimeImmutable('-4 days'))->format('Y-m-d'), 'max_days_overdue' => 4]], $report['overdue_approvals']);
        self::assertSame(1, $report['acknowledgement']['requests']);
        self::assertSame([['Sales', 2, 1, 50]], array_map(static fn (array $row): array => [$row['unit'], $row['total'], $row['acknowledged'], $row['completion_percent']], $report['acknowledgement']['by_unit']));
        self::assertSame(400, $this->callAs('clerk', 'GET', '/api/dms/reports?from=2026-05-01&to=2026-04-01'));
        self::assertSame(400, $this->callAs('clerk', 'GET', '/api/dms/reports?from=bad'));
        self::assertSame(200, $this->callAs('clerk', 'GET', '/api/dms/reports?from=2000-01-01&to=2000-12-31'));
        self::assertSame([], $this->json()['approval_time']['by_kind'], 'Nothing decided in that period.');
    }

    private function archivedYearsAgo(int $id, int $years): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $document = $entityManager->find(Document::class, $id);
        (new \ReflectionProperty($document, 'archivedAt'))->setValue($document, new \DateTimeImmutable(sprintf('-%d years', $years)));
        $entityManager->flush();
    }

    private function statusOf(int $id): string
    {
        self::assertSame(200, $this->callAs('clerk', 'GET', "/api/dms/documents/$id"));

        return $this->json()['status'];
    }

    private function messages(): string
    {
        return implode("\n", array_column($this->json()['items'], 'message'));
    }

    /** @param array<string, mixed> $fields */
    private function registered(string $title, array $fields = []): int
    {
        $id = $this->draft($title, $fields);
        $this->register($id);

        return $id;
    }

    private function register(int $id): void
    {
        self::assertSame(200, $this->callAs('clerk', 'POST', "/api/dms/documents/$id/register"), $this->lastBody);
    }

    /** @param array<string, mixed> $fields */
    private function draft(string $title, array $fields = [], string $type = 'POL'): int
    {
        self::assertSame(201, $this->callAs('user', 'POST', '/api/dms/documents', json_encode(['type_id' => $this->ids[$type], 'title' => $title] + $fields)), $this->lastBody);
        $id = $this->json()['id'];
        $path = (string) tempnam(sys_get_temp_dir(), 'dms-rec');
        file_put_contents($path, 'Policy text');
        $this->tempFiles[] = $path;
        static::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->loginUser(static::getContainer()->get(EntityManagerInterface::class)->find(User::class, $this->users['user']->getId()), 'api');
        $this->client->request('POST', "/api/dms/documents/$id/versions", [], ['file' => new UploadedFile($path, 'policy.txt', null, null, true)]);
        self::assertSame(201, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        return $id;
    }

    private function id(string $who): int
    {
        return (int) $this->users[$who]->getId();
    }
}
