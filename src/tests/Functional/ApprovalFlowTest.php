<?php

namespace App\Tests\Functional;

use App\Entity\ApprovalStep;
use App\Entity\Notification;
use App\Entity\User;
use App\Service\ReminderGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Approval by subordination. Structure:
 *   ACME (head: admin) > SALES (head: manager) > EAST (head: buyer; staff: user, the author)
 *   ACME > IT (staff: inventory, finance)
 * The author's reporting line is buyer (1) → manager (2) → admin (3). "clerk" holds ROLE_DMS_ADMIN and no position.
 */
final class ApprovalFlowTest extends ApiTestCase
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

        foreach ([['ACME', 'organization', null], ['SALES', 'unit', 'ACME'], ['EAST', 'section', 'SALES'], ['IT', 'unit', 'ACME']] as [$code, $kind, $parent]) {
            $body = ['code' => $code, 'name' => ucfirst(strtolower($code)), 'kind' => $kind] + ($parent ? ['parent_id' => $this->ids[$parent]] : []);
            self::assertSame(201, $this->callAs('admin', 'POST', '/api/org/units', json_encode($body)), $this->lastBody);
            $this->ids[$code] = $this->json()['id'];
        }
        foreach ([['admin', 'ACME', true], ['manager', 'SALES', true], ['buyer', 'EAST', true], ['user', 'EAST', false], ['inventory', 'IT', false], ['finance', 'IT', false]] as [$who, $unit, $head]) {
            self::assertSame(201, $this->callAs('admin', 'POST', '/api/org/positions', json_encode(['unit_id' => $this->ids[$unit], 'title' => ucfirst($who), 'is_head' => $head])));
            self::assertSame(201, $this->callAs('admin', 'POST', '/api/org/employments', json_encode(['user_id' => $this->id($who), 'position_id' => $this->json()['id'], 'starts_on' => '2026-01-01'])), $this->lastBody);
        }
        $this->ids['ORDER'] = $this->type(['code' => 'ORDER', 'name' => 'Order', 'requires_approval' => true, 'auto_register' => true, 'number_pattern' => 'ORD-{YYYY}-{SEQ:3}']);
        $this->ids['MEMO'] = $this->type(['code' => 'MEMO', 'name' => 'Memo', 'requires_approval' => false]);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function test_parallel_and_sequential_stages_end_in_automatic_registration(): void
    {
        $doc = $this->draft('ORDER', 'Purchase order 7');
        self::assertSame(200, $this->callAs('user', 'GET', "/api/dms/documents/$doc/approval/candidates"));
        $candidates = $this->json();
        self::assertSame(['buyer@example.test', 'manager@example.test', 'admin@example.test'], array_column($candidates['superiors'], 'email'));
        self::assertSame([1, 2, 3], array_column($candidates['superiors'], 'level'));
        self::assertNotContains('user@example.test', array_column($candidates['colleagues'], 'email'));
        self::assertNotContains('clerk@example.test', array_column($candidates['colleagues'], 'email'), 'No position, no review.');

        $due = (new \DateTimeImmutable('+3 days'))->format('Y-m-d');
        $this->submit($doc, [
            ['rule' => 'all', 'steps' => [['assignee_id' => $this->id('inventory'), 'kind' => 'review'], ['assignee_id' => $this->id('buyer'), 'kind' => 'approve', 'due_on' => $due]]],
            ['rule' => 'any', 'steps' => [['assignee_id' => $this->id('manager'), 'kind' => 'sign'], ['assignee_id' => $this->id('admin'), 'kind' => 'sign']]],
        ]);
        $round = $this->json()['rounds'][0];
        self::assertSame(['active', 'waiting'], array_column($round['stages'], 'status'));
        self::assertSame('Buyer', $round['stages'][0]['steps'][1]['assignee']['position'], 'Position snapshot.');
        self::assertSame('East', $round['stages'][0]['steps'][1]['assignee']['unit']);
        self::assertSame(1, $round['version_number']);
        self::assertSame(409, $this->callAs('user', 'PATCH', "/api/dms/documents/$doc", '{"title":"Changed in approval"}'));
        self::assertSame(409, $this->callAs('user', 'POST', "/api/dms/documents/$doc/approval/submit", '{}'), 'Already in approval.');

        // Participants see the document even from another unit; the inbox and the bell show the request.
        self::assertSame(200, $this->callAs('inventory', 'GET', "/api/dms/documents/$doc"));
        self::assertSame(200, $this->callAs('inventory', 'GET', '/api/dms/documents'));
        self::assertSame(1, $this->json()['total']);
        self::assertSame(200, $this->callAs('buyer', 'GET', '/api/dms/approvals/counts'));
        self::assertSame(['awaiting' => 1, 'overdue' => 0, 'consultations' => 0], $this->json());
        self::assertSame(200, $this->callAs('manager', 'GET', '/api/dms/approvals/inbox'));
        self::assertSame([], $this->json(), 'Stage 2 waits.');
        self::assertSame(200, $this->callAs('buyer', 'GET', '/api/notifications'));
        self::assertStringContainsString('approve "Purchase order 7"', $this->messages());

        $buyerStep = $this->inboxStep('buyer');
        self::assertTrue($buyerStep['can_act']);
        self::assertSame($due, $buyerStep['due_on']);
        self::assertSame(403, $this->callAs('manager', 'POST', "/api/dms/approvals/steps/{$buyerStep['id']}/decision", '{"decision":"approve"}'), 'Not their step.');
        self::assertSame(400, $this->callAs('buyer', 'POST', "/api/dms/approvals/steps/{$buyerStep['id']}/decision", '{"decision":"reject","comment":" "}'));
        self::assertSame(200, $this->callAs('buyer', 'POST', "/api/dms/approvals/steps/{$buyerStep['id']}/decision", '{"decision":"approve","comment":"Budget checked"}'), $this->lastBody);
        self::assertSame('active', $this->json()['rounds'][0]['stages'][0]['status'], 'The review is still open.');
        self::assertSame(409, $this->callAs('buyer', 'POST', "/api/dms/approvals/steps/{$buyerStep['id']}/decision", '{"decision":"approve"}'), 'Already decided.');

        $this->decide('inventory', 'approve');
        self::assertSame(['completed', 'active'], array_column($this->json()['rounds'][0]['stages'], 'status'));
        $this->decide('manager', 'approve', 'Signed');

        self::assertSame(200, $this->callAs('user', 'GET', "/api/dms/documents/$doc"));
        $document = $this->json();
        self::assertSame('in_force', $document['status'], 'Approved, then registered automatically.');
        self::assertSame('ORD-'.date('Y').'-001', $document['registration_number']);
        self::assertSame('automatic registration', $document['registered_by']);
        self::assertSame(200, $this->callAs('user', 'GET', "/api/dms/documents/$doc/approval"));
        $round = $this->json()['rounds'][0];
        self::assertSame('approved', $round['status']);
        self::assertSame('skipped', $round['stages'][1]['steps'][1]['status'], 'The second signatory was not needed.');
        self::assertSame(['submitted', 'approved_with_comments', 'approved', 'approved_with_comments'], array_column($round['actions'], 'action'));
        self::assertSame(hash('sha256', 'Order body v1'), $round['actions'][1]['version_sha256']);
        self::assertSame(200, $this->callAs('user', 'GET', '/api/notifications'));
        self::assertStringContainsString('is approved', $this->messages());
    }

    public function test_subordination_rules_for_route_participants(): void
    {
        $doc = $this->draft('ORDER', 'Order');
        $submit = fn (string $kind, int $assignee): int => $this->callAs('user', 'POST', "/api/dms/documents/$doc/approval/submit", json_encode(['stages' => [['steps' => [['assignee_id' => $assignee, 'kind' => $kind]]]]]));

        self::assertSame(409, $submit('approve', $this->id('inventory')), 'Not a superior of the author.');
        self::assertStringContainsString('reporting line', $this->lastBody);
        self::assertSame(409, $submit('sign', $this->id('finance')));
        self::assertSame(409, $submit('review', $this->id('user')), 'The author cannot review their own document.');
        self::assertSame(409, $submit('review', $this->id('clerk')), 'No position in the structure.');
        self::assertSame(404, $submit('approve', 99999));
        self::assertSame(400, $submit('veto', $this->id('buyer')));
        self::assertSame(400, $this->callAs('user', 'POST', "/api/dms/documents/$doc/approval/submit", json_encode(['stages' => [['steps' => []]]])));
        self::assertSame(400, $this->callAs('user', 'POST', "/api/dms/documents/$doc/approval/submit", json_encode(['stages' => [['steps' => [['assignee_id' => $this->id('buyer'), 'due_on' => '2020-01-01']]]]])));
        self::assertSame(400, $this->callAs('user', 'POST', "/api/dms/documents/$doc/approval/submit", json_encode(['stages' => [['rule' => 'most', 'steps' => [['assignee_id' => $this->id('buyer')]]]]])));
        self::assertSame(400, $this->callAs('user', 'POST', "/api/dms/documents/$doc/approval/submit", json_encode(['stages' => [['steps' => [['assignee_id' => $this->id('buyer')], ['assignee_id' => $this->id('buyer')]]]]])), 'Twice in one stage.');
        self::assertSame(400, $this->callAs('user', 'POST', "/api/dms/documents/$doc/approval/submit", '{}'), 'No stages and no template.');
        self::assertSame(404, $this->callAs('inventory', 'POST', "/api/dms/documents/$doc/approval/submit", '{}'), 'Cannot even see the draft.');
        self::assertSame(403, $this->callAs('buyer', 'POST', "/api/dms/documents/$doc/approval/submit", '{}'), 'The unit head sees it but only the author or a clerk sends it.');
        self::assertSame(200, $this->callAs('user', 'GET', "/api/dms/documents/$doc"));
        self::assertSame('draft', $this->json()['status'], 'Nothing was half-submitted.');

        $empty = $this->callAs('user', 'POST', '/api/dms/documents', json_encode(['type_id' => $this->ids['MEMO'], 'title' => 'No file']));
        self::assertSame(201, $empty);
        self::assertSame(409, $this->callAs('user', 'POST', '/api/dms/documents/'.$this->json()['id'].'/approval/submit', json_encode(['stages' => [['steps' => [['assignee_id' => $this->id('buyer')]]]]])));

        // Templates resolve levels up the author's reporting line.
        self::assertSame(200, $this->callAs('clerk', 'PATCH', '/api/dms/types/'.$this->ids['ORDER'], json_encode(['route_template' => [['kind' => 'approve', 'level' => 1, 'due_days' => 2], ['kind' => 'sign', 'level' => 3]]])));
        self::assertSame(200, $this->callAs('user', 'GET', "/api/dms/documents/$doc/approval/candidates"));
        self::assertSame(['buyer@example.test', 'admin@example.test'], array_column(array_column($this->json()['template'], 'assignee'), 'email'));
        self::assertSame(201, $this->callAs('user', 'POST', "/api/dms/documents/$doc/approval/submit", '{}'), $this->lastBody);
        self::assertSame(['buyer@example.test', 'admin@example.test'], array_map(static fn (array $stage): string => $stage['steps'][0]['assignee']['email'], $this->json()['rounds'][0]['stages']));
        self::assertSame((new \DateTimeImmutable('+2 days'))->format('Y-m-d'), $this->json()['rounds'][0]['stages'][0]['steps'][0]['due_on']);

        self::assertSame(400, $this->callAs('clerk', 'PATCH', '/api/dms/types/'.$this->ids['MEMO'], json_encode(['route_template' => [['kind' => 'approve', 'level' => 9]]])));
        self::assertSame(200, $this->callAs('clerk', 'PATCH', '/api/dms/types/'.$this->ids['MEMO'], json_encode(['route_template' => [['kind' => 'approve', 'level' => 4]]])));
        $memo = $this->draft('MEMO', 'Too high');
        self::assertSame(409, $this->callAs('user', 'POST', "/api/dms/documents/$memo/approval/submit", '{}'));
        self::assertStringContainsString('reporting line is shorter', $this->lastBody);
    }

    public function test_reject_return_withdraw_and_resubmission(): void
    {
        $doc = $this->draft('ORDER', 'Order');
        $this->submit($doc, [['steps' => [['assignee_id' => $this->id('buyer')]]], ['steps' => [['assignee_id' => $this->id('manager')]]]]);
        $this->decide('buyer', 'reject', 'Wrong supplier');
        self::assertSame(200, $this->callAs('user', 'GET', "/api/dms/documents/$doc"));
        self::assertSame('rejected', $this->json()['status']);
        self::assertTrue($this->json()['permissions']['can_edit'], 'The author can fix it.');
        self::assertSame(200, $this->callAs('user', 'GET', '/api/notifications'));
        self::assertStringContainsString('Wrong supplier', $this->messages());

        // Resubmitting without stages re-runs the previous route (restart mode).
        self::assertSame(201, $this->upload('user', "/api/dms/documents/$doc/versions", 'Order body v2'));
        self::assertSame(201, $this->callAs('user', 'POST', "/api/dms/documents/$doc/approval/submit", '{"comment":"Supplier fixed"}'), $this->lastBody);
        $rounds = $this->json()['rounds'];
        self::assertSame([2, 1], array_column($rounds, 'round'));
        self::assertSame(2, $rounds[0]['version_number']);
        self::assertSame('resubmitted', $rounds[0]['actions'][0]['action']);
        self::assertSame('Supplier fixed', $rounds[0]['actions'][0]['comment']);
        self::assertSame('active', $rounds[0]['stages'][0]['status']);

        $this->decide('buyer', 'return', 'Attach the quote');
        self::assertSame(200, $this->callAs('user', 'GET', "/api/dms/documents/$doc"));
        self::assertSame('draft', $this->json()['status'], 'Returned for revision.');

        $this->submit($doc, [['steps' => [['assignee_id' => $this->id('buyer')]]]]);
        $stepId = $this->inboxStep('buyer')['id'];
        self::assertSame(403, $this->callAs('buyer', 'POST', "/api/dms/documents/$doc/approval/withdraw", '{}'));
        self::assertSame(200, $this->callAs('user', 'POST', "/api/dms/documents/$doc/approval/withdraw", '{"comment":"Not needed"}'));
        self::assertSame('withdrawn', $this->json()['document_status']);
        self::assertSame(409, $this->callAs('buyer', 'POST', "/api/dms/approvals/steps/$stepId/decision", '{"decision":"approve"}'));
        self::assertSame(409, $this->callAs('user', 'POST', "/api/dms/documents/$doc/approval/withdraw", '{}'));
        self::assertSame(200, $this->callAs('buyer', 'GET', '/api/dms/approvals/inbox'));
        self::assertSame([], $this->json());
    }

    public function test_continue_mode_keeps_stages_completed_in_the_previous_round(): void
    {
        self::assertSame(200, $this->callAs('clerk', 'PATCH', '/api/dms/types/'.$this->ids['ORDER'], '{"resubmit_mode":"continue","auto_register":false}'));
        $doc = $this->draft('ORDER', 'Order');
        $this->submit($doc, [['steps' => [['assignee_id' => $this->id('buyer')]]], ['steps' => [['assignee_id' => $this->id('manager')]]]]);
        $this->decide('buyer', 'approve');
        $this->decide('manager', 'reject', 'Too expensive');

        self::assertSame(201, $this->upload('user', "/api/dms/documents/$doc/versions", 'Cheaper order'));
        self::assertSame(201, $this->callAs('user', 'POST', "/api/dms/documents/$doc/approval/submit", '{}'));
        $stages = $this->json()['rounds'][0]['stages'];
        self::assertSame(['carried', 'active'], array_column($stages, 'status'));
        self::assertSame('carried', $stages[0]['steps'][0]['status']);
        self::assertSame('buyer@example.test', $stages[0]['steps'][0]['decided_by']);
        $this->decide('manager', 'approve');
        self::assertSame(200, $this->callAs('user', 'GET', "/api/dms/documents/$doc"));
        self::assertSame('approved', $this->json()['status'], 'No automatic registration for this type now.');
        self::assertTrue($this->json()['permissions']['can_edit']);
        self::assertSame(200, $this->callAs('clerk', 'POST', "/api/dms/documents/$doc/register"), $this->lastBody);
    }

    public function test_deputies_act_on_behalf_and_approvers_consult_their_subordinates(): void
    {
        $doc = $this->draft('ORDER', 'Order');
        $this->submit($doc, [['steps' => [['assignee_id' => $this->id('manager'), 'kind' => 'approve']]]]);
        $managerStep = $this->inboxStep('manager')['id'];

        // The manager asks the buyer (their subordinate) for an opinion; IT staff are not their subordinates.
        self::assertSame(200, $this->callAs('manager', 'GET', "/api/dms/approvals/steps/$managerStep/forward-candidates"));
        self::assertSame(['buyer@example.test'], array_column($this->json(), 'email'), 'The author is left out.');
        self::assertSame(409, $this->callAs('manager', 'POST', "/api/dms/approvals/steps/$managerStep/forward", json_encode(['assignee_id' => $this->id('inventory')])));
        self::assertSame(403, $this->callAs('buyer', 'POST', "/api/dms/approvals/steps/$managerStep/forward", json_encode(['assignee_id' => $this->id('buyer')])));
        self::assertSame(201, $this->callAs('manager', 'POST', "/api/dms/approvals/steps/$managerStep/forward", json_encode(['assignee_id' => $this->id('buyer'), 'comment' => 'Check the quantities'])), $this->lastBody);
        self::assertSame(409, $this->callAs('manager', 'POST', "/api/dms/approvals/steps/$managerStep/forward", json_encode(['assignee_id' => $this->id('buyer')])), 'Already asked.');
        $consultation = $this->inboxStep('buyer');
        self::assertSame('consult', $consultation['kind']);
        self::assertSame('manager@example.test', $consultation['requested_by']);
        self::assertSame(200, $this->callAs('buyer', 'GET', '/api/dms/approvals/counts'));
        self::assertSame(1, $this->json()['consultations']);
        self::assertSame(400, $this->callAs('buyer', 'POST', "/api/dms/approvals/steps/{$consultation['id']}/decision", '{"decision":"review"}'), 'A review needs text.');
        self::assertSame(409, $this->callAs('buyer', 'POST', "/api/dms/approvals/steps/{$consultation['id']}/decision", '{"decision":"approve"}'), 'Advisory only.');
        self::assertSame(200, $this->callAs('buyer', 'POST', "/api/dms/approvals/steps/{$consultation['id']}/decision", '{"decision":"review","comment":"Quantities are right"}'));
        self::assertSame('active', $this->json()['rounds'][0]['stages'][0]['status'], 'The review does not decide the stage.');
        self::assertSame(200, $this->callAs('manager', 'GET', '/api/notifications'));
        self::assertStringContainsString('buyer@example.test reviewed', $this->messages());

        // The manager is away: their deputy decides on their behalf, and both names are recorded.
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        self::assertSame(201, $this->callAs('manager', 'POST', '/api/delegations', json_encode(['deputy_id' => $this->id('finance'), 'starts_on' => $today, 'ends_on' => $today])));
        self::assertSame(200, $this->callAs('finance', 'GET', '/api/dms/approvals/inbox'));
        $inbox = $this->json();
        self::assertCount(1, $inbox);
        self::assertSame('manager@example.test', $inbox[0]['acting_for']);
        self::assertSame(200, $this->callAs('finance', 'GET', "/api/dms/documents/$doc"), 'The deputy can read what they decide.');
        self::assertSame(200, $this->callAs('finance', 'POST', "/api/dms/approvals/steps/$managerStep/decision", '{"decision":"approve","comment":"As deputy"}'), $this->lastBody);
        $step = $this->json()['rounds'][0]['stages'][0]['steps'][0];
        self::assertSame(['approved', 'finance@example.test', 'manager@example.test'], [$step['status'], $step['decided_by'], $step['on_behalf_of']]);
        $last = $this->json()['rounds'][0]['actions'];
        self::assertSame('manager@example.test', end($last)['on_behalf_of']);
    }

    public function test_the_author_adds_a_reviewer_to_the_running_stage(): void
    {
        $doc = $this->draft('ORDER', 'Order');
        $this->submit($doc, [['steps' => [['assignee_id' => $this->id('buyer')]]]]);
        self::assertSame(403, $this->callAs('buyer', 'POST', "/api/dms/documents/$doc/approval/reviewers", json_encode(['assignee_id' => $this->id('inventory')])));
        self::assertSame(409, $this->callAs('user', 'POST', "/api/dms/documents/$doc/approval/reviewers", json_encode(['assignee_id' => $this->id('clerk')])));
        self::assertSame(400, $this->callAs('user', 'POST', "/api/dms/documents/$doc/approval/reviewers", json_encode(['assignee_id' => $this->id('buyer')])), 'Already in the stage.');
        self::assertSame(201, $this->callAs('user', 'POST', "/api/dms/documents/$doc/approval/reviewers", json_encode(['assignee_id' => $this->id('inventory')])), $this->lastBody);
        $this->decide('buyer', 'approve');
        self::assertSame('active', $this->json()['rounds'][0]['stages'][0]['status'], 'The added reviewer must agree as well.');
        $this->decide('inventory', 'approve');
        self::assertSame('approved', $this->json()['rounds'][0]['status']);
        self::assertSame(409, $this->callAs('user', 'POST', "/api/dms/documents/$doc/approval/reviewers", json_encode(['assignee_id' => $this->id('finance')])), 'Not running any more.');
    }

    public function test_version_comments_and_comparison(): void
    {
        $doc = $this->draft('MEMO', 'Memo', 'Staff travel by train. Claims close monthly.');
        self::assertSame(201, $this->upload('user', "/api/dms/documents/$doc/versions", 'Staff travel by train. Claims close weekly.'));

        self::assertSame(201, $this->callAs('manager', 'POST', "/api/dms/documents/$doc/comments", json_encode(['version_number' => 1, 'quote' => 'monthly', 'body' => 'Too slow'])), $this->lastBody);
        $comment = $this->json();
        self::assertTrue($comment['can_resolve'], 'The writer may resolve.');
        self::assertSame(404, $this->callAs('manager', 'POST', "/api/dms/documents/$doc/comments", json_encode(['version_number' => 9, 'body' => 'x'])));
        self::assertInvalid($this->callAs('manager', 'POST', "/api/dms/documents/$doc/comments", json_encode(['version_number' => 1, 'body' => ''])));
        self::assertSame(404, $this->callAs('finance', 'GET', "/api/dms/documents/$doc/comments"), 'Cannot read the draft.');
        self::assertSame(200, $this->callAs('user', 'GET', "/api/dms/documents/$doc/comments"));
        self::assertSame(['Too slow'], array_column($this->json(), 'body'));
        self::assertTrue($this->json()[0]['can_resolve'], 'The document author may resolve.');
        self::assertSame(403, $this->callAs('buyer', 'POST', "/api/dms/documents/$doc/comments/{$comment['id']}/resolve"));
        self::assertSame(200, $this->callAs('user', 'POST', "/api/dms/documents/$doc/comments/{$comment['id']}/resolve"));
        self::assertSame('user@example.test', $this->json()['resolved_by']);
        self::assertSame(409, $this->callAs('manager', 'POST', "/api/dms/documents/$doc/comments/{$comment['id']}/resolve"));
        self::assertSame(404, $this->callAs('user', 'POST', "/api/dms/documents/$doc/comments/99999/resolve"));

        self::assertSame(200, $this->callAs('manager', 'GET', "/api/dms/documents/$doc/versions/compare?from=1&to=2"));
        $diff = $this->json();
        self::assertTrue($diff['comparable']);
        self::assertSame([['op' => 'equal', 'text' => 'Staff travel by train.'], ['op' => 'added', 'text' => 'Claims close weekly.'], ['op' => 'removed', 'text' => 'Claims close monthly.']], $diff['segments']);
        self::assertSame(400, $this->callAs('manager', 'GET', "/api/dms/documents/$doc/versions/compare?from=1"));
        self::assertSame(404, $this->callAs('manager', 'GET', "/api/dms/documents/$doc/versions/compare?from=1&to=7"));
        self::assertSame(404, $this->callAs('finance', 'GET', "/api/dms/documents/$doc/versions/compare?from=1&to=2"));
    }

    public function test_due_approval_steps_are_reminded_and_escalated_to_the_assignees_superior(): void
    {
        $doc = $this->draft('ORDER', 'Late order');
        $this->submit($doc, [['steps' => [['assignee_id' => $this->id('buyer'), 'due_on' => (new \DateTimeImmutable('today'))->format('Y-m-d')]]]]);
        $generator = static::getContainer()->get(ReminderGenerator::class);
        $generator->run(new \DateTimeImmutable());
        self::assertSame(200, $this->callAs('buyer', 'GET', '/api/notifications'));
        self::assertStringContainsString('Approval of "Late order" is due today', $this->messages());
        $notice = array_values(array_filter($this->json()['items'], static fn (array $item): bool => $item['kind'] === Notification::DUE_SOON));
        self::assertSame(['document', $doc], [$notice[0]['subject_type'], $notice[0]['subject_id']]);

        // Five days later it is overdue and, past the default three days, escalates to the buyer's superior.
        $generator = static::getContainer()->get(ReminderGenerator::class);
        $counts = $generator->run(new \DateTimeImmutable('+5 days'));
        self::assertSame(1, $counts['overdue']);
        self::assertSame(1, $counts['escalations']);
        self::assertSame(['due_soon' => 0, 'overdue' => 0, 'escalations' => 0], static::getContainer()->get(ReminderGenerator::class)->run(new \DateTimeImmutable('+5 days')), 'Idempotent.');
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $escalation = $entityManager->getRepository(Notification::class)->findOneBy(['kind' => Notification::ESCALATION]);
        self::assertSame('manager@example.test', $escalation?->getUser()->getUserIdentifier());

        self::assertSame(200, $this->callAs('buyer', 'GET', '/api/dms/approvals/inbox'));
        self::assertFalse($this->json()[0]['is_overdue'], 'Not yet overdue today.');
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $step = $entityManager->getRepository(ApprovalStep::class)->find($this->json()[0]['id']);
        (new \ReflectionProperty($step, 'dueOn'))->setValue($step, new \DateTimeImmutable('-1 day'));
        $entityManager->flush();
        self::assertSame(200, $this->callAs('buyer', 'GET', '/api/dms/approvals/counts'));
        self::assertSame(1, $this->json()['overdue']);
    }

    /** Messages of the last notifications response, joined. */
    private function messages(): string
    {
        return implode("\n", array_column($this->json()['items'], 'message'));
    }

    /** @param list<array<string, mixed>> $stages */
    private function submit(int $doc, array $stages): void
    {
        self::assertSame(201, $this->callAs('user', 'POST', "/api/dms/documents/$doc/approval/submit", json_encode(['stages' => $stages])), $this->lastBody);
    }

    private function decide(string $who, string $decision, ?string $comment = null): void
    {
        $step = $this->inboxStep($who);
        self::assertSame(200, $this->callAs($who, 'POST', "/api/dms/approvals/steps/{$step['id']}/decision", json_encode(['decision' => $decision, 'comment' => $comment])), $this->lastBody);
    }

    /** @return array<string, mixed> the first open step in the user's inbox */
    private function inboxStep(string $who): array
    {
        self::assertSame(200, $this->callAs($who, 'GET', '/api/dms/approvals/inbox'));
        self::assertNotEmpty($this->json(), "$who has nothing to decide.");

        return $this->json()[0];
    }

    private function draft(string $type, string $title, string $body = 'Order body v1'): int
    {
        self::assertSame(201, $this->callAs('user', 'POST', '/api/dms/documents', json_encode(['type_id' => $this->ids[$type], 'title' => $title])), $this->lastBody);
        $id = $this->json()['id'];
        self::assertSame(201, $this->upload('user', "/api/dms/documents/$id/versions", $body), $this->lastBody);

        return $id;
    }

    /** @param array<string, mixed> $body */
    private function type(array $body): int
    {
        self::assertSame(201, $this->callAs('clerk', 'POST', '/api/dms/types', json_encode($body)), $this->lastBody);

        return $this->json()['id'];
    }

    private function id(string $who): int
    {
        return (int) $this->users[$who]->getId();
    }

    private function upload(string $as, string $uri, string $content): int
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'dms-approval');
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;
        static::ensureKernelShutdown();
        $this->client = static::createClient();
        $managed = static::getContainer()->get(EntityManagerInterface::class)->find(User::class, $this->users[$as]->getId());
        $this->client->loginUser($managed, 'api');
        $this->client->request('POST', $uri, [], ['file' => new UploadedFile($path, 'body.txt', null, null, true)]);
        $this->lastBody = (string) $this->client->getResponse()->getContent();

        return $this->client->getResponse()->getStatusCode();
    }
}
