<?php

namespace App\Tests\Functional;

use App\Entity\Notification;
use App\Entity\User;
use App\Service\ReminderGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Acknowledgement campaigns. Structure:
 *   ACME (head: admin) > SALES (head: manager; staff: finance) > EAST (head: buyer; staff: user)
 *   ACME > IT (head: inventory)
 * "clerk" holds ROLE_DMS_ADMIN and no position; "newbie" has no post until a test hires them.
 * Documents are written by "user" and registered by the clerk.
 */
final class AcknowledgementFlowTest extends ApiTestCase
{
    /** @var array<string, int> */
    private array $ids = [];
    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        foreach (['clerk' => ['ROLE_DMS_ADMIN'], 'newbie' => []] as $name => $roles) {
            $extra = (new User())->setEmail($name.'@example.test')->setPasswordHash('x')->setRoles($roles);
            $entityManager->persist($extra);
            $this->users[$name] = $extra;
        }
        $entityManager->flush();

        foreach ([['ACME', 'organization', null], ['SALES', 'unit', 'ACME'], ['EAST', 'section', 'SALES'], ['IT', 'unit', 'ACME']] as [$code, $kind, $parent]) {
            $body = ['code' => $code, 'name' => ucfirst(strtolower($code)), 'kind' => $kind] + ($parent ? ['parent_id' => $this->ids[$parent]] : []);
            self::assertSame(201, $this->callAs('admin', 'POST', '/api/org/units', json_encode($body)), $this->lastBody);
            $this->ids[$code] = $this->json()['id'];
        }
        foreach ([['admin', 'ACME', true], ['manager', 'SALES', true], ['finance', 'SALES', false], ['buyer', 'EAST', true], ['user', 'EAST', false], ['inventory', 'IT', true]] as [$who, $unit, $head]) {
            self::assertSame(201, $this->callAs('admin', 'POST', '/api/org/positions', json_encode(['unit_id' => $this->ids[$unit], 'title' => ucfirst($who), 'is_head' => $head])));
            $this->ids['position:'.$who] = $this->json()['id'];
            self::assertSame(201, $this->callAs('admin', 'POST', '/api/org/employments', json_encode(['user_id' => $this->id($who), 'position_id' => $this->json()['id'], 'starts_on' => '2026-01-01'])), $this->lastBody);
        }
        self::assertSame(201, $this->callAs('clerk', 'POST', '/api/dms/types', json_encode(['code' => 'POL', 'name' => 'Policy', 'requires_approval' => false])), $this->lastBody);
        $this->ids['POL'] = $this->json()['id'];
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function test_a_manager_asks_their_subtree_and_follows_the_status_per_unit(): void
    {
        $doc = $this->registered('Travel policy');
        $draft = $this->draft('Unregistered policy');

        self::assertSame(200, $this->callAs('manager', 'GET', "/api/dms/documents/$doc/acknowledgements/audience"));
        $audience = $this->json();
        self::assertFalse($audience['organization_wide']);
        self::assertSame(['East', 'Sales'], array_column($audience['units'], 'name'));
        self::assertSame(['buyer@example.test', 'finance@example.test', 'user@example.test'], array_column($audience['people'], 'email'), 'The sender is not offered.');
        self::assertSame(403, $this->callAs('user', 'GET', "/api/dms/documents/$doc/acknowledgements/audience"), 'Not a head.');
        self::assertSame(200, $this->callAs('user', 'GET', "/api/dms/documents/$doc/acknowledgements"));
        self::assertFalse($this->json()['can_send']);

        self::assertSame(409, $this->send('manager', $doc, [['kind' => 'unit', 'id' => $this->ids['IT']]]), 'IT is outside the manager\'s subtree.');
        self::assertStringContainsString('outside the units you manage', $this->lastBody);
        self::assertSame(409, $this->send('manager', $doc, [['kind' => 'position', 'id' => $this->ids['position:inventory']]]));
        self::assertSame(409, $this->send('manager', $doc, [['kind' => 'user', 'id' => $this->id('inventory')]]));
        self::assertSame(403, $this->send('user', $doc, [['kind' => 'unit', 'id' => $this->ids['EAST']]]));
        self::assertSame(409, $this->send('manager', $draft, [['kind' => 'unit', 'id' => $this->ids['EAST']]]), 'Only registered documents.');
        self::assertSame(400, $this->send('manager', $doc, []));
        self::assertSame(400, $this->send('manager', $doc, [['kind' => 'team', 'id' => 1]]));
        self::assertSame(404, $this->send('manager', $doc, [['kind' => 'unit', 'id' => 99999]]));
        self::assertSame(400, $this->send('manager', $doc, [['kind' => 'unit', 'id' => $this->ids['SALES']]], ['due_on' => '2020-01-01']));

        $due = (new \DateTimeImmutable('+5 days'))->format('Y-m-d');
        self::assertSame(201, $this->send('manager', $doc, [['kind' => 'unit', 'id' => $this->ids['SALES'], 'include_subunits' => true]], ['due_on' => $due, 'message' => 'Read before your next trip']), $this->lastBody);
        $board = $this->json();
        $requestId = $board['id'];
        self::assertSame(['total' => 3, 'pending' => 3, 'viewed' => 0, 'acknowledged' => 0, 'excused' => 0, 'open' => 3, 'overdue' => 0, 'late' => 0, 'completion_percent' => 0], $board['counts']);
        self::assertSame(['Sales (with sub-units)'], array_column($board['targets'], 'label'));
        $units = [];
        foreach ($board['recipients'] as $row) {
            $units[$row['user']['email']] = [$row['unit']['name'], $row['position']];
        }
        self::assertSame(['buyer@example.test' => ['East', 'Buyer'], 'user@example.test' => ['East', 'User'], 'finance@example.test' => ['Sales', 'Finance']], $units, 'Snapshot of unit and position.');
        self::assertTrue($board['permissions']['can_control']);

        // The recipient's inbox, counter and bell.
        self::assertSame(200, $this->callAs('user', 'GET', '/api/dms/acknowledgements/counts'));
        self::assertSame(['to_acknowledge' => 1, 'overdue' => 0], $this->json());
        $mine = $this->inboxRow('user');
        self::assertSame('Read before your next trip', $mine['request']['message']);
        self::assertTrue($mine['needs_opening']);
        self::assertFalse($mine['can_acknowledge']);
        self::assertSame(200, $this->callAs('user', 'GET', '/api/notifications'));
        self::assertStringContainsString('Please read and acknowledge "Travel policy" by '.$due, $this->messages());

        // Opening the requested version is required before confirming.
        self::assertSame(409, $this->callAs('user', 'POST', "/api/dms/acknowledgements/recipients/{$mine['id']}/acknowledge"));
        self::assertStringContainsString('Open the document', $this->lastBody);
        self::assertSame(404, $this->callAs('finance', 'POST', "/api/dms/acknowledgements/recipients/{$mine['id']}/acknowledge"), 'Someone else\'s confirmation.');
        self::assertSame(200, $this->callAs('user', 'GET', "/api/dms/documents/$doc/versions/1/file"));
        self::assertSame(200, $this->callAs('user', 'POST', "/api/dms/acknowledgements/recipients/{$mine['id']}/acknowledge"), $this->lastBody);
        self::assertSame('acknowledged', $this->json()['status']);
        self::assertSame(hash('sha256', 'Policy text'), $this->json()['acknowledged_sha256']);
        self::assertNotNull($this->json()['viewed_at']);
        self::assertSame(409, $this->callAs('user', 'POST', "/api/dms/acknowledgements/recipients/{$mine['id']}/acknowledge"), 'Only once.');
        self::assertSame(200, $this->callAs('user', 'GET', '/api/dms/acknowledgements/inbox'));
        self::assertSame([], $this->json());

        // The sender sees every unit, rolled up; the East head only East.
        self::assertSame(200, $this->callAs('manager', 'GET', "/api/dms/acknowledgements/$requestId"));
        $tree = array_map(static fn (array $row): array => [$row['name'], $row['depth'], $row['total']['total'], $row['total']['acknowledged'], $row['own']['total']], $this->json()['units']);
        self::assertSame([['Sales', 0, 3, 1, 1], ['East', 1, 2, 1, 2]], $tree);
        self::assertSame(33, $this->json()['counts']['completion_percent']);
        self::assertSame(200, $this->callAs('buyer', 'GET', '/api/dms/acknowledgements'));
        self::assertCount(1, $this->json());
        self::assertTrue($this->json()[0]['is_partial']);
        self::assertSame(2, $this->json()[0]['counts']['total']);
        self::assertSame(200, $this->callAs('buyer', 'GET', "/api/dms/acknowledgements/$requestId?status=acknowledged"));
        self::assertSame(['user@example.test'], array_column(array_column($this->json()['recipients'], 'user'), 'email'));
        self::assertSame([['East', 0]], array_map(static fn (array $row): array => [$row['name'], $row['depth']], $this->json()['units']));
        self::assertFalse($this->json()['permissions']['can_control']);
        self::assertSame(400, $this->callAs('buyer', 'GET', "/api/dms/acknowledgements/$requestId?status=lost"));
        self::assertSame(404, $this->callAs('inventory', 'GET', "/api/dms/acknowledgements/$requestId"), 'Not affected, not the sender.');
        self::assertSame(200, $this->callAs('inventory', 'GET', '/api/dms/acknowledgements'));
        self::assertSame([], $this->json());

        // Excusing: the person's managers or the sender, with a reason; never oneself.
        $buyerRow = $this->rowFor($requestId, 'buyer');
        $financeRow = $this->rowFor($requestId, 'finance');
        self::assertSame(403, $this->callAs('buyer', 'POST', "/api/dms/acknowledgements/recipients/$buyerRow/excuse", '{"reason":"Holiday"}'));
        self::assertSame(400, $this->callAs('manager', 'POST', "/api/dms/acknowledgements/recipients/$buyerRow/excuse", '{"reason":" "}'));
        self::assertSame(200, $this->callAs('manager', 'POST', "/api/dms/acknowledgements/recipients/$buyerRow/excuse", '{"reason":"Parental leave"}'), $this->lastBody);
        self::assertSame(['excused', 'Parental leave', 'manager@example.test'], [$this->json()['status'], $this->json()['excuse_reason'], $this->json()['excused_by']]);
        self::assertSame(404, $this->callAs('buyer', 'POST', "/api/dms/acknowledgements/recipients/$financeRow/excuse", '{"reason":"x"}'), 'Finance is not in East.');
        self::assertSame(200, $this->callAs('manager', 'DELETE', "/api/dms/acknowledgements/recipients/$buyerRow/excuse"));
        self::assertSame('pending', $this->json()['status']);
        self::assertSame(200, $this->callAs('manager', 'POST', "/api/dms/acknowledgements/recipients/$buyerRow/excuse", '{"reason":"Parental leave"}'));

        // Exports for the sender.
        self::assertSame(200, $this->callAs('manager', 'GET', "/api/dms/acknowledgements/$requestId/export.csv"));
        self::assertStringStartsWith('text/csv', (string) $this->client->getResponse()->headers->get('Content-Type'));
        $lines = array_values(array_filter(explode("\n", trim(substr($this->lastBody, 3)))));
        self::assertCount(4, $lines);
        self::assertStringStartsWith('Employee,Position,Unit,Status', $lines[0]);
        self::assertStringContainsString('user@example.test,User,East,acknowledged', $this->lastBody);
        self::assertStringContainsString('Parental leave', $this->lastBody);
        self::assertSame(200, $this->callAs('buyer', 'GET', "/api/dms/acknowledgements/$requestId/sheet.pdf"));
        self::assertSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringStartsWith('%PDF', $this->lastBody);

        // Finance completes the request; the sender hears once.
        self::assertSame(200, $this->callAs('finance', 'GET', "/api/dms/documents/$doc/versions/1/file?inline=1"));
        self::assertSame(200, $this->callAs('finance', 'POST', "/api/dms/acknowledgements/recipients/$financeRow/acknowledge"));
        self::assertSame(200, $this->callAs('manager', 'GET', '/api/notifications'));
        self::assertStringContainsString('Everyone asked has acknowledged "Travel policy"', $this->messages());
        self::assertSame(200, $this->callAs('manager', 'GET', "/api/dms/acknowledgements/$requestId"));
        self::assertSame(100, $this->json()['counts']['completion_percent'], 'Excused people do not count against completion.');

        self::assertSame(200, $this->callAs('user', 'GET', '/api/dms/acknowledgements/history'));
        self::assertSame([['Travel policy', 'acknowledged']], array_map(static fn (array $row): array => [$row['request']['document']['title'], $row['status']], $this->json()));
        self::assertSame(200, $this->callAs('user', 'GET', "/api/dms/documents/$doc"));
        self::assertContains('acknowledged', array_column($this->json()['events'], 'action'));
    }

    public function test_clerks_address_anyone_and_recipients_can_read_confidential_documents(): void
    {
        $doc = $this->registered('Salary bands', ['confidentiality' => 'confidential']);
        self::assertSame(404, $this->callAs('inventory', 'GET', "/api/dms/documents/$doc"));
        self::assertSame(200, $this->callAs('clerk', 'GET', "/api/dms/documents/$doc/acknowledgements/audience"));
        self::assertTrue($this->json()['organization_wide']);
        self::assertSame(['Acme', 'East', 'It', 'Sales'], array_column($this->json()['units'], 'name'));

        self::assertSame(201, $this->send('clerk', $doc, [['kind' => 'unit', 'id' => $this->ids['IT']], ['kind' => 'user', 'id' => $this->id('newbie')]], ['require_open' => false]), $this->lastBody);
        $board = $this->json();
        self::assertSame(['inventory@example.test', 'newbie@example.test'], array_column(array_column($board['recipients'], 'user'), 'email'));
        self::assertNull($board['recipients'][1]['unit'], 'No post, no unit snapshot.');
        self::assertSame(['It', 'No unit recorded'], array_column($board['units'], 'name'));

        self::assertSame(200, $this->callAs('inventory', 'GET', "/api/dms/documents/$doc"), 'Recipients may read it.');
        self::assertSame(200, $this->callAs('inventory', 'GET', '/api/dms/documents'));
        self::assertSame([$doc], array_column($this->json()['items'], 'id'));
        self::assertSame(404, $this->callAs('finance', 'GET', "/api/dms/documents/$doc"), 'Still hidden from everyone else.');
        $row = $this->inboxRow('newbie');
        self::assertTrue($row['can_acknowledge'], 'This request does not require opening the file.');
        self::assertSame(200, $this->callAs('newbie', 'POST', "/api/dms/acknowledgements/recipients/{$row['id']}/acknowledge"));
        self::assertSame(200, $this->callAs('inventory', 'GET', "/api/dms/documents/$doc/acknowledgements"));
        self::assertSame([[true, 1]], array_map(static fn (array $request): array => [$request['is_partial'], $request['counts']['total']], $this->json()['requests']), 'The IT head sees the IT part only.');
        self::assertSame(['pending'], array_column($this->json()['mine'], 'status'));
    }

    public function test_reminders_deadlines_added_recipients_joiners_and_closing(): void
    {
        $doc = $this->registered('Safety rules');
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        self::assertSame(201, $this->send('manager', $doc, [['kind' => 'unit', 'id' => $this->ids['EAST']]], ['due_on' => $today]), $this->lastBody);
        $requestId = $this->json()['id'];
        self::assertCount(2, $this->json()['recipients']);

        self::assertSame(200, $this->callAs('manager', 'POST', "/api/dms/acknowledgements/$requestId/remind", '{}'));
        self::assertSame(['reminded' => 2, 'skipped' => 0], $this->json());
        self::assertSame(200, $this->callAs('manager', 'POST', "/api/dms/acknowledgements/$requestId/remind", '{}'));
        self::assertSame(['reminded' => 0, 'skipped' => 2], $this->json(), 'One manual reminder per person per day.');
        self::assertSame(200, $this->callAs('user', 'GET', '/api/notifications'));
        self::assertStringContainsString('Reminder from manager@example.test', $this->messages());
        $userRow = $this->rowFor($requestId, 'user');
        self::assertSame(200, $this->callAs('buyer', 'POST', "/api/dms/acknowledgements/$requestId/remind", json_encode(['recipient_ids' => [$userRow]])), 'A head reminds their own people.');
        self::assertSame(404, $this->callAs('finance', 'POST', "/api/dms/acknowledgements/$requestId/remind", '{}'));
        self::assertSame(404, $this->callAs('manager', 'POST', "/api/dms/acknowledgements/$requestId/remind", '{"recipient_ids":[99999]}'));

        // Scheduler: due today, then overdue and escalated to each person's superior.
        static::getContainer()->get(ReminderGenerator::class)->run(new \DateTimeImmutable());
        self::assertSame(200, $this->callAs('user', 'GET', '/api/notifications'));
        self::assertStringContainsString('Acknowledgement of "Safety rules" is due today', $this->messages());
        $counts = static::getContainer()->get(ReminderGenerator::class)->run(new \DateTimeImmutable('+5 days'));
        self::assertSame([2, 2], [$counts['overdue'], $counts['escalations']]);
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $escalated = array_map(static fn (Notification $notification): string => $notification->getUser()->getUserIdentifier().': '.$notification->getMessage(), $entityManager->getRepository(Notification::class)->findBy(['kind' => Notification::ESCALATION], ['id' => 'ASC']));
        sort($escalated);
        self::assertStringStartsWith('buyer@example.test: Escalation: Acknowledgement of "Safety rules" assigned to user@example.test', $escalated[0]);
        self::assertStringStartsWith('manager@example.test: Escalation: Acknowledgement of "Safety rules" assigned to buyer@example.test', $escalated[1]);

        // Deadline: only the sender or a clerk, only later.
        $later = (new \DateTimeImmutable('+10 days'))->format('Y-m-d');
        self::assertSame(403, $this->callAs('buyer', 'POST', "/api/dms/acknowledgements/$requestId/deadline", json_encode(['due_on' => $later])));
        self::assertSame(400, $this->callAs('manager', 'POST', "/api/dms/acknowledgements/$requestId/deadline", json_encode(['due_on' => '2020-01-01'])));
        self::assertSame(400, $this->callAs('manager', 'POST', "/api/dms/acknowledgements/$requestId/deadline", json_encode(['due_on' => $today])), 'Not an extension.');
        self::assertSame(200, $this->callAs('manager', 'POST', "/api/dms/acknowledgements/$requestId/deadline", json_encode(['due_on' => $later])), $this->lastBody);
        self::assertSame($later, $this->json()['due_on']);

        // Adding a position, then hiring someone into the targeted unit adds them automatically.
        self::assertSame(201, $this->callAs('manager', 'POST', "/api/dms/acknowledgements/$requestId/recipients", json_encode(['targets' => [['kind' => 'position', 'id' => $this->ids['position:finance']]]])), $this->lastBody);
        self::assertSame(1, $this->json()['added']);
        self::assertSame(201, $this->callAs('manager', 'POST', "/api/dms/acknowledgements/$requestId/recipients", json_encode(['targets' => [['kind' => 'user', 'id' => $this->id('finance')]]])));
        self::assertSame(0, $this->json()['added'], 'Already asked.');
        self::assertSame(201, $this->callAs('admin', 'POST', '/api/org/positions', json_encode(['unit_id' => $this->ids['EAST'], 'title' => 'Assistant'])));
        self::assertSame(201, $this->callAs('admin', 'POST', '/api/org/employments', json_encode(['user_id' => $this->id('newbie'), 'position_id' => $this->json()['id'], 'starts_on' => $today])), $this->lastBody);
        $joined = $this->inboxRow('newbie');
        self::assertSame('joined', $joined['source']);
        self::assertSame(['East', 'Assistant'], [$joined['unit']['name'], $joined['position']]);
        self::assertSame(200, $this->callAs('manager', 'GET', "/api/dms/acknowledgements/$requestId"));
        self::assertSame(4, $this->json()['counts']['total']);

        self::assertSame(403, $this->callAs('buyer', 'POST', "/api/dms/acknowledgements/$requestId/close"));
        self::assertSame(200, $this->callAs('manager', 'POST', "/api/dms/acknowledgements/$requestId/close"));
        self::assertSame('closed', $this->json()['status']);
        self::assertSame(409, $this->callAs('user', 'POST', "/api/dms/acknowledgements/recipients/$userRow/acknowledge"));
        self::assertSame(409, $this->callAs('manager', 'POST', "/api/dms/acknowledgements/$requestId/remind", '{}'));
        self::assertSame(200, $this->callAs('user', 'GET', '/api/dms/acknowledgements/inbox'));
        self::assertSame([], $this->json());
    }

    public function test_reissue_for_a_replacing_document_and_archiving_closes_requests(): void
    {
        $old = $this->registered('Expense rules 2025');
        self::assertSame(201, $this->send('manager', $old, [['kind' => 'unit', 'id' => $this->ids['SALES'], 'include_subunits' => true]], ['require_open' => false]));
        $first = $this->json()['id'];
        $userRow = $this->rowFor($first, 'user');
        self::assertSame(200, $this->callAs('user', 'POST', "/api/dms/acknowledgements/recipients/$userRow/acknowledge"));

        self::assertSame(409, $this->callAs('manager', 'POST', "/api/dms/acknowledgements/$first/reissue", '{}'), 'No newer version of the same document.');
        $unrelated = $this->registered('Unrelated');
        self::assertSame(409, $this->callAs('manager', 'POST', "/api/dms/acknowledgements/$first/reissue", json_encode(['document_id' => $unrelated])));

        $new = $this->draft('Expense rules 2026');
        self::assertSame(201, $this->callAs('user', 'POST', "/api/dms/documents/$new/links", json_encode(['target_id' => $old, 'kind' => 'replaces'])), $this->lastBody);
        self::assertSame(200, $this->callAs('clerk', 'POST', "/api/dms/documents/$new/register"), $this->lastBody);
        self::assertSame(403, $this->callAs('buyer', 'POST', "/api/dms/acknowledgements/$first/reissue", json_encode(['document_id' => $new])));
        $due = (new \DateTimeImmutable('+7 days'))->format('Y-m-d');
        self::assertSame(201, $this->callAs('manager', 'POST', "/api/dms/acknowledgements/$first/reissue", json_encode(['document_id' => $new, 'due_on' => $due])), $this->lastBody);
        $second = $this->json();
        self::assertSame([$new, $first, $due, 3], [$second['document']['id'], $second['previous_id'], $second['due_on'], $second['counts']['total']]);
        self::assertSame(0, $second['counts']['acknowledged'], 'Everyone confirms the new edition again.');
        self::assertSame(200, $this->callAs('manager', 'GET', "/api/dms/acknowledgements/$first"));
        self::assertSame('closed', $this->json()['status']);

        self::assertSame(200, $this->callAs('clerk', 'POST', "/api/dms/documents/$new/archive"), $this->lastBody);
        self::assertSame(200, $this->callAs('manager', 'GET', "/api/dms/acknowledgements/{$second['id']}"));
        self::assertSame('closed', $this->json()['status'], 'Archiving closes open requests.');
        self::assertSame(409, $this->send('manager', $new, [['kind' => 'unit', 'id' => $this->ids['EAST']]]), 'Archived documents cannot be sent.');
    }

    /**
     * @param list<array<string, mixed>> $targets
     * @param array<string, mixed> $extra
     */
    private function send(string $as, int $doc, array $targets, array $extra = []): int
    {
        return $this->callAs($as, 'POST', "/api/dms/documents/$doc/acknowledgements", json_encode(['targets' => $targets] + $extra));
    }

    /** @return array<string, mixed> the first row of the user's acknowledgement inbox */
    private function inboxRow(string $who): array
    {
        self::assertSame(200, $this->callAs($who, 'GET', '/api/dms/acknowledgements/inbox'));
        self::assertNotEmpty($this->json(), "$who has nothing to acknowledge.");

        return $this->json()[0];
    }

    private function rowFor(int $requestId, string $who): int
    {
        self::assertSame(200, $this->callAs('clerk', 'GET', "/api/dms/acknowledgements/$requestId"));
        foreach ($this->json()['recipients'] as $row) {
            if ($row['user']['email'] === "$who@example.test") {
                return $row['id'];
            }
        }
        self::fail("$who is not on request $requestId.");
    }

    private function messages(): string
    {
        return implode("\n", array_column($this->json()['items'], 'message'));
    }

    /** @param array<string, mixed> $fields */
    private function registered(string $title, array $fields = []): int
    {
        $id = $this->draft($title, $fields);
        self::assertSame(200, $this->callAs('clerk', 'POST', "/api/dms/documents/$id/register"), $this->lastBody);
        self::assertSame('in_force', $this->json()['status']);

        return $id;
    }

    /** @param array<string, mixed> $fields */
    private function draft(string $title, array $fields = []): int
    {
        self::assertSame(201, $this->callAs('user', 'POST', '/api/dms/documents', json_encode(['type_id' => $this->ids['POL'], 'title' => $title] + $fields)), $this->lastBody);
        $id = $this->json()['id'];
        $path = (string) tempnam(sys_get_temp_dir(), 'dms-ack');
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
