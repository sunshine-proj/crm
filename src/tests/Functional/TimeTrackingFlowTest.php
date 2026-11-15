<?php

namespace App\Tests\Functional;

/** Phase 8: time visibility, time reports and CSV export, task estimates, timesheet batches, workload and budget warnings. */
final class TimeTrackingFlowTest extends ApiTestCase
{
    /** @return array{managed: int, other: int} project "managed" is run by the manager user; "other" has no manager */
    private function projects(): array
    {
        $managed = $this->createClientAndProject(['manager_id' => $this->users['manager']->getId(), 'hourly_rate' => '60.00', 'billing_currency' => 'EUR', 'budget_amount' => '100.00', 'budget_currency' => 'EUR']);
        self::assertSame(201, $this->callAs('user', 'POST', '/api/projects', json_encode(['title' => '=HYPERLINK("x")', 'description' => 'd', 'client_id' => $managed['client'], 'status' => 'active'])), $this->lastBody);

        return ['managed' => $managed['project'], 'other' => $this->json()['id']];
    }

    private function log(string $as, int $projectId, int $minutes, string $date = '2026-03-02', ?int $taskId = null, bool $billable = false): int
    {
        $body = ['project_id' => $projectId, 'task_id' => $taskId, 'work_date' => $date, 'duration_minutes' => $minutes, 'description' => 'Work', 'billable' => $billable];
        self::assertSame(201, $this->callAs($as, 'POST', '/api/operations/time-entries', json_encode($body)), $this->lastBody);

        return $this->json()['id'];
    }

    /** @param array<string, mixed> $fields */
    private function task(int $projectId, array $fields = []): array
    {
        $body = $fields + ['title' => 'Design', 'description' => 'd', 'project_id' => $projectId, 'start_datetime' => '2026-03-02T09:00:00+00:00', 'end_datetime' => '2026-03-06T17:00:00+00:00'];
        self::assertSame(201, $this->callAs('user', 'POST', '/api/tasks', json_encode($body)), $this->lastBody);

        return $this->json();
    }

    /** @return list<string> emails of the listed entries' authors */
    private function authors(string $as, string $query): array
    {
        self::assertSame(200, $this->callAs($as, 'GET', '/api/operations/time-entries?'.$query), $this->lastBody);
        $emails = array_column($this->json(), 'user_email');
        sort($emails);

        return $emails;
    }

    public function test_time_entries_are_visible_to_their_author_the_project_manager_finance_and_administrators_only(): void
    {
        ['managed' => $managed, 'other' => $other] = $this->projects();
        $this->log('user', $managed, 60);
        $this->log('inventory', $managed, 30);
        $this->log('user', $other, 45);
        $foreign = $this->log('inventory', $other, 15);

        $everyone = ['inventory@example.test', 'user@example.test'];
        self::assertSame($everyone, $this->authors('admin', 'project_id='.$managed));
        self::assertSame($everyone, $this->authors('finance', 'project_id='.$other));
        self::assertSame($everyone, $this->authors('manager', 'project_id='.$managed), 'the project manager sees the whole team');
        self::assertSame([], $this->authors('manager', 'project_id='.$other), 'but not projects they do not manage');
        self::assertSame(['user@example.test'], $this->authors('user', 'project_id='.$other), 'others see only their own time');
        self::assertSame(['user@example.test', 'user@example.test'], $this->authors('user', 'user_id='.$this->users['user']->getId()), 'own time across projects');
        self::assertSame([], $this->authors('user', 'user_id='.$this->users['inventory']->getId()));
        self::assertSame(['inventory@example.test', 'inventory@example.test'], $this->authors('inventory', 'mine=1&from=2026-03-01&to=2026-03-08'), 'the timesheet asks for its own week');
        self::assertSame(['inventory@example.test'], $this->authors('manager', 'user_id='.$this->users['inventory']->getId()), 'a manager sees a colleague only on managed projects');

        self::assertSame(404, $this->callAs('user', 'GET', '/api/operations/time-entries/'.$foreign.'/history'), 'history follows the same rule');
        self::assertSame(200, $this->callAs('inventory', 'GET', '/api/operations/time-entries/'.$foreign.'/history'));
        self::assertSame(200, $this->callAs('admin', 'GET', '/api/operations/time-entries/'.$foreign.'/history'));
        self::assertInvalid($this->callAs('user', 'GET', '/api/operations/time-entries?user_id=abc'));
    }

    public function test_the_time_entry_list_filters_by_date_status_and_billable_flag(): void
    {
        ['managed' => $managed] = $this->projects();
        $this->log('user', $managed, 60, '2026-03-02', billable: true);
        $approved = $this->log('user', $managed, 30, '2026-03-10');
        $this->callAs('manager', 'POST', '/api/operations/time-entries/'.$approved.'/decision', '{"decision":"approved"}');

        self::assertCount(1, $this->authors('admin', 'project_id='.$managed.'&from=2026-03-05'));
        self::assertCount(1, $this->authors('admin', 'project_id='.$managed.'&to=2026-03-02'));
        self::assertCount(2, $this->authors('admin', 'project_id='.$managed.'&from=2026-03-02&to=2026-03-10'));
        self::assertCount(1, $this->authors('admin', 'project_id='.$managed.'&status=approved'));
        self::assertCount(1, $this->authors('admin', 'project_id='.$managed.'&billable=true'));
        self::assertInvalid($this->callAs('admin', 'GET', '/api/operations/time-entries?project_id='.$managed.'&from=2026-03-10&to=2026-03-01'));
        self::assertInvalid($this->callAs('admin', 'GET', '/api/operations/time-entries?project_id='.$managed.'&status=maybe'));
        self::assertInvalid($this->callAs('admin', 'GET', '/api/operations/time-entries?project_id='.$managed.'&billable=yes'));
        self::assertInvalid($this->callAs('admin', 'GET', '/api/operations/time-entries?project_id='.$managed.'&from=2026-02-30'));
    }

    public function test_the_report_totals_time_per_employee_project_and_task_within_the_viewer_scope(): void
    {
        ['managed' => $managed, 'other' => $other] = $this->projects();
        $this->callAs('admin', 'PUT', '/api/operations/users/'.$this->users['user']->getId().'/cost-rate', '{"hourly_rate":"30.00","currency":"EUR"}');
        $task = $this->task($managed, ['estimated_minutes' => 120])['id'];
        $approved = $this->log('user', $managed, 90, taskId: $task, billable: true);
        $this->callAs('manager', 'POST', '/api/operations/time-entries/'.$approved.'/decision', '{"decision":"approved"}');
        $rejected = $this->log('user', $managed, 20, taskId: $task);
        $this->callAs('manager', 'POST', '/api/operations/time-entries/'.$rejected.'/decision', '{"decision":"rejected"}');
        $this->log('user', $managed, 40);
        $this->log('inventory', $managed, 30, '2026-03-03');
        $this->log('inventory', $other, 15);

        self::assertSame(200, $this->callAs('admin', 'GET', '/api/operations/time-report'), $this->lastBody);
        $report = $this->json();
        self::assertSame('all', $report['scope']);
        self::assertSame(['employee', 'project'], $report['group_by'], 'employee x project by default');
        self::assertCount(3, $report['rows']);
        self::assertSame(['employee_id' => $this->users['inventory']->getId(), 'employee_email' => 'inventory@example.test', 'project_id' => $other, 'project_title' => '=HYPERLINK("x")'], array_slice($report['rows'][0], 0, 4));
        $userRow = $report['rows'][2];
        self::assertSame(['user@example.test', $managed, 150, 90, 40, 20, 90], [$userRow['employee_email'], $userRow['project_id'], $userRow['total_minutes'], $userRow['approved_minutes'], $userRow['pending_minutes'], $userRow['rejected_minutes'], $userRow['billable_minutes']]);
        self::assertArrayHasKey('approved_cost', $userRow, 'administrators inherit finance cost visibility');
        self::assertSame(195, $report['totals']['total_minutes']);

        self::assertSame(200, $this->callAs('finance', 'GET', '/api/operations/time-report?group_by=employee'));
        self::assertSame([['currency' => 'EUR', 'amount' => '45.00']], $this->json()['rows'][1]['approved_cost'], '90 approved minutes at 30.00/hour');
        self::assertSame([['currency' => 'EUR', 'amount' => '45.00']], $this->json()['totals']['approved_cost']);

        self::assertSame(200, $this->callAs('admin', 'GET', '/api/operations/time-report?group_by=project,task,employee&project_id='.$managed));
        $rows = $this->json()['rows'];
        self::assertSame([$task, 120, 'user@example.test', 110], [$rows[0]['task_id'], $rows[0]['task_estimated_minutes'], $rows[0]['employee_email'], $rows[0]['total_minutes']]);
        self::assertNull($rows[1]['task_id'], 'time without a task is its own group, after named tasks');
        self::assertSame(200, $this->callAs('admin', 'GET', '/api/operations/time-report?group_by=task&from=2026-03-03'));
        self::assertSame(30, $this->json()['totals']['total_minutes']);
        self::assertSame(200, $this->callAs('admin', 'GET', '/api/operations/time-report?group_by=employee&user_id='.$this->users['inventory']->getId().'&billable=false'));
        self::assertSame(45, $this->json()['totals']['total_minutes']);

        self::assertSame(200, $this->callAs('manager', 'GET', '/api/operations/time-report?group_by=employee'));
        self::assertSame('managed', $this->json()['scope']);
        self::assertSame(180, $this->json()['totals']['total_minutes'], 'the manager sees the managed project only');
        self::assertSame(200, $this->callAs('user', 'GET', '/api/operations/time-report?group_by=project'));
        self::assertSame('own', $this->json()['scope']);
        self::assertSame(150, $this->json()['totals']['total_minutes']);
        self::assertArrayNotHasKey('approved_cost', $this->json()['totals'], 'cost is finance-only');
        self::assertArrayNotHasKey('approved_cost', $this->json()['rows'][0]);

        foreach (['group_by=team', 'group_by=task,task', 'group_by=employee,project,task,employee', 'from=2026-03-05&to=2026-03-01', 'status=done', 'project_id=0'] as $query) {
            self::assertSame(400, $this->callAs('admin', 'GET', '/api/operations/time-report?'.$query), $query);
        }
    }

    public function test_the_report_exports_as_csv_with_spreadsheet_formulas_neutralised(): void
    {
        ['managed' => $managed, 'other' => $other] = $this->projects();
        $this->log('user', $managed, 90);
        $this->log('user', $other, 20);

        self::assertSame(200, $this->callAs('user', 'GET', '/api/operations/time-report/export?group_by=project'));
        self::assertStringStartsWith('text/csv', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('attachment; filename=time-report.csv', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        $lines = array_map(static fn (string $line): array => str_getcsv($line, escape: ''), array_filter(explode("\n", $this->lastBody)));
        self::assertSame(['Project', 'Total minutes', 'Total hours', 'Approved minutes', 'Pending minutes', 'Rejected minutes', 'Billable minutes'], $lines[0]);
        self::assertSame(["'=HYPERLINK(\"x\")", '20', '0.33'], array_slice($lines[1], 0, 3));
        self::assertSame(['Build', '90', '1.50'], array_slice($lines[2], 0, 3));
        self::assertSame('Time report exported.', $this->auditRecords()[array_key_last($this->auditRecords())]['message']);

        self::assertSame(200, $this->callAs('finance', 'GET', '/api/operations/time-report/export?group_by=employee,task'));
        self::assertStringStartsWith('Employee,Task,"Task estimate (minutes)"', $this->lastBody);
        self::assertSame(400, $this->callAs('user', 'GET', '/api/operations/time-report/export?group_by=nope'));
    }

    public function test_tasks_carry_an_estimate_and_report_logged_time_against_it(): void
    {
        ['managed' => $managed] = $this->projects();
        self::assertInvalid($this->callAs('user', 'POST', '/api/tasks', json_encode(['title' => 'x', 'description' => 'd', 'project_id' => $managed, 'start_datetime' => '2026-03-02T09:00:00+00:00', 'end_datetime' => '2026-03-03T09:00:00+00:00', 'estimated_minutes' => 0])));
        $task = $this->task($managed, ['estimated_minutes' => 120]);
        self::assertSame([120, 0, 0, false], [$task['estimated_minutes'], $task['logged_minutes'], $task['approved_minutes'], $task['over_estimate']]);
        $approved = $this->log('user', $managed, 100, taskId: $task['id']);
        $this->callAs('manager', 'POST', '/api/operations/time-entries/'.$approved.'/decision', '{"decision":"approved"}');
        $this->log('user', $managed, 30, taskId: $task['id']);
        $rejected = $this->log('user', $managed, 500, taskId: $task['id']);
        $this->callAs('manager', 'POST', '/api/operations/time-entries/'.$rejected.'/decision', '{"decision":"rejected"}');

        self::assertSame(200, $this->callAs('user', 'GET', '/api/tasks/'.$task['id']));
        self::assertSame([130, 100, true], [$this->json()['logged_minutes'], $this->json()['approved_minutes'], $this->json()['over_estimate']], 'rejected time does not count');
        self::assertSame(200, $this->callAs('user', 'GET', '/api/tasks'));
        self::assertSame(130, $this->json()[0]['logged_minutes']);

        $uri = '/api/tasks/'.$task['id'];
        self::assertSame(200, $this->callAs('user', 'PATCH', $uri, '{"title":"Design v2"}'), $this->lastBody);
        self::assertSame(120, $this->json()['estimated_minutes'], 'an update without the field keeps the estimate');
        self::assertSame(200, $this->callAs('user', 'PATCH', $uri, '{"estimated_minutes":240}'));
        self::assertSame([240, false], [$this->json()['estimated_minutes'], $this->json()['over_estimate']]);
        self::assertSame(200, $this->callAs('user', 'PATCH', $uri, '{"estimated_minutes":null}'));
        self::assertNull($this->json()['estimated_minutes']);
        self::assertFalse($this->json()['over_estimate']);
        self::assertInvalid($this->callAs('user', 'PATCH', $uri, '{"estimated_minutes":6000001}'));
    }

    public function test_a_timesheet_batch_is_saved_completely_or_not_at_all(): void
    {
        ['managed' => $managed, 'other' => $other] = $this->projects();
        $entry = static fn (int $project, string $date, int $minutes): array => ['project_id' => $project, 'work_date' => $date, 'duration_minutes' => $minutes, 'description' => 'Timesheet'];
        $uri = '/api/operations/time-entries/batch';

        self::assertSame(201, $this->callAs('user', 'POST', $uri, json_encode(['entries' => [$entry($managed, '2026-03-02', 60), $entry($other, '2026-03-03', 30)]])), $this->lastBody);
        self::assertSame([60, 30], array_column($this->json(), 'duration_minutes'));
        self::assertSame(['pending_approval', 'pending_approval'], array_column($this->json(), 'approval_status'));

        self::assertSame(404, $this->callAs('user', 'POST', $uri, json_encode(['entries' => [$entry($managed, '2026-03-04', 60), $entry(999, '2026-03-04', 30)]])));
        self::assertStringStartsWith('Entry 2: ', $this->json()['error']);
        self::assertSame(409, $this->callAs('user', 'POST', $uri, json_encode(['entries' => [['billable' => true] + $entry($other, '2026-03-04', 30)]])), 'billable time still needs a project rate');
        self::assertSame(422, $this->callAs('user', 'POST', $uri, json_encode(['entries' => [$entry($managed, '2026-03-04', 1441)]])));
        self::assertSame(400, $this->callAs('user', 'POST', $uri, json_encode(['entries' => [['duration_minutes' => '60'] + $entry($managed, '2026-03-04', 60)]])));
        self::assertSame(400, $this->callAs('user', 'POST', $uri, json_encode(['entries' => ['not an object']])));
        self::assertInvalid($this->callAs('user', 'POST', $uri, '{"entries":[]}'));
        self::assertSame(['user@example.test', 'user@example.test'], $this->authors('user', 'user_id='.$this->users['user']->getId()), 'failed batches saved nothing');
    }

    public function test_workload_shows_logged_and_planned_minutes_per_week(): void
    {
        ['managed' => $managed] = $this->projects();
        $assignee = $this->users['user']->getId();
        $task = $this->task($managed, ['assignee_id' => $assignee, 'estimated_minutes' => 2400, 'due_date' => '2026-03-11'])['id'];
        $this->task($managed, ['assignee_id' => $assignee, 'estimated_minutes' => 60, 'status' => 'done', 'due_date' => '2026-03-11']);
        $this->task($managed, ['assignee_id' => $this->users['inventory']->getId(), 'estimated_minutes' => 60, 'due_date' => '2026-02-01']);
        $this->log('user', $managed, 120, '2026-03-03', $task);
        $this->log('user', $managed, 480, '2026-03-10');

        self::assertSame(200, $this->callAs('admin', 'GET', '/api/operations/time-report/workload?from=2026-03-04&to=2026-03-15'), $this->lastBody);
        $workload = $this->json();
        self::assertSame(['2026-03-02', '2026-03-09'], $workload['weeks'], 'aligned to whole ISO weeks');
        self::assertSame('2026-03-15', $workload['to']);
        [$inventory, $user] = $workload['employees'];
        self::assertSame(60, $inventory['weeks'][0]['planned_minutes'], 'overdue work counts in the first week shown');
        self::assertSame([120, 0, false], [$user['weeks'][0]['logged_minutes'], $user['weeks'][0]['planned_minutes'], $user['weeks'][0]['overloaded']]);
        self::assertSame([480, 2280, true], [$user['weeks'][1]['logged_minutes'], $user['weeks'][1]['planned_minutes'], $user['weeks'][1]['overloaded']], 'remaining estimate 2400 - 120; done tasks ignored');

        self::assertSame(200, $this->callAs('inventory', 'GET', '/api/operations/time-report/workload?from=2026-03-04&to=2026-03-15'));
        self::assertSame(['inventory@example.test'], array_column($this->json()['employees'], 'employee_email'), 'others see only themselves');
        self::assertSame(400, $this->callAs('admin', 'GET', '/api/operations/time-report/workload?from=2026-01-01&to=2026-12-31'), 'at most 26 weeks');
        self::assertSame(400, $this->callAs('admin', 'GET', '/api/operations/time-report/workload?from=2026-03-15&to=2026-03-01'));
        self::assertSame(200, $this->callAs('user', 'GET', '/api/operations/time-report/workload'), 'defaults to an eight-week window');
        self::assertCount(8, $this->json()['weeks']);
    }

    public function test_profitability_warns_when_approved_cost_reaches_80_percent_of_the_budget(): void
    {
        ['managed' => $managed] = $this->projects();
        $this->callAs('admin', 'PUT', '/api/operations/users/'.$this->users['user']->getId().'/cost-rate', '{"hourly_rate":"60.00","currency":"EUR"}');
        $uri = '/api/operations/projects/'.$managed.'/profitability?currency=EUR';
        $entry = $this->log('user', $managed, 70);
        $this->callAs('manager', 'POST', '/api/operations/time-entries/'.$entry.'/decision', '{"decision":"approved"}');
        self::assertSame(200, $this->callAs('finance', 'GET', $uri));
        self::assertSame([70, false, 80], [$this->json()['budget_consumed_percent'], $this->json()['budget_warning'], $this->json()['budget_warning_percent']]);

        $entry = $this->log('user', $managed, 10);
        $this->callAs('manager', 'POST', '/api/operations/time-entries/'.$entry.'/decision', '{"decision":"approved"}');
        self::assertSame(200, $this->callAs('finance', 'GET', $uri));
        self::assertSame([80, true], [$this->json()['budget_consumed_percent'], $this->json()['budget_warning']]);
        self::assertSame(200, $this->callAs('finance', 'GET', $uri.'&from=2026-03-01'));
        self::assertSame([null, false], [$this->json()['budget_consumed_percent'], $this->json()['budget_warning']], 'the whole budget is not compared with a period');
    }
}
