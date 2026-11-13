<?php

namespace App\Tests\Functional;

/** Day-to-day CRM records over HTTP: tasks and dependencies, notes, contacts, the activity timeline and news. */
final class CrmFlowTest extends ApiTestCase
{
    /** @param array<string, mixed> $fields */
    private function task(int $projectId, string $title = 'Design', array $fields = []): array
    {
        $body = $fields + ['title' => $title, 'description' => 'd', 'project_id' => $projectId, 'start_datetime' => '2026-03-02T09:00:00+00:00', 'end_datetime' => '2026-03-03T17:00:00+00:00'];
        self::assertSame(201, $this->callAs('user', 'POST', '/api/tasks', json_encode($body)), $this->lastBody);

        return $this->json();
    }

    public function test_tasks_are_created_assigned_updated_and_deleted(): void
    {
        $project = $this->createClientAndProject()['project'];
        $assignee = $this->users['manager']->getId();
        $base = ['title' => 'Design', 'description' => 'd', 'project_id' => $project, 'start_datetime' => '2026-03-02T09:00:00+00:00', 'end_datetime' => '2026-03-03T17:00:00+00:00'];

        self::assertInvalid($this->callAs('user', 'POST', '/api/tasks', '{"title":"No dates"}'));
        self::assertInvalid($this->callAs('user', 'POST', '/api/tasks', json_encode(['end_datetime' => '2026-03-01T09:00:00+00:00'] + $base)), 'end before start');
        self::assertInvalid($this->callAs('user', 'POST', '/api/tasks', json_encode(['status' => 'someday'] + $base)));
        self::assertSame(404, $this->callAs('user', 'POST', '/api/tasks', json_encode(['project_id' => 999] + $base)));
        self::assertSame(404, $this->callAs('user', 'POST', '/api/tasks', json_encode(['assignee_id' => 999] + $base)));

        $task = $this->task($project, 'Design', ['assignee_id' => $assignee, 'priority' => 'high', 'due_date' => '2026-03-05']);
        self::assertSame('todo', $task['status']);
        self::assertSame('high', $task['priority']);
        self::assertSame($assignee, $task['assignee_id']);
        $uri = '/api/tasks/'.$task['id'];

        self::assertSame(200, $this->callAs('user', 'PATCH', $uri, json_encode(['status' => 'in_progress', 'title' => 'Design v2'] + $base)), $this->lastBody);
        self::assertSame('in_progress', $this->json()['status']);
        self::assertSame('Design v2', $this->json()['title']);
        self::assertInvalid($this->callAs('user', 'PATCH', $uri, json_encode(['end_datetime' => '2026-03-01T09:00:00+00:00'] + $base)));
        self::assertSame(404, $this->callAs('user', 'PATCH', $uri, json_encode(['project_id' => 999] + $base)));
        self::assertSame(404, $this->callAs('user', 'PATCH', $uri, json_encode(['assignee_id' => 999] + $base)));
        self::assertSame(404, $this->callAs('user', 'PATCH', '/api/tasks/999', json_encode($base)));
        self::assertSame(200, $this->callAs('user', 'GET', $uri));
        self::assertSame(404, $this->callAs('user', 'GET', '/api/tasks/999'));
        self::assertSame(200, $this->callAs('user', 'GET', '/api/tasks'));
        self::assertCount(1, $this->json());
        self::assertSame(200, $this->callAs('user', 'GET', '/api/dashboard/summary'));
        self::assertSame(['client_count' => 1, 'active_project_count' => 1, 'open_task_count' => 1], $this->json());

        self::assertContains($this->callAs('user', 'DELETE', $uri), [200, 204]);
        self::assertSame(404, $this->callAs('user', 'DELETE', $uri));
        self::assertSame(200, $this->callAs('user', 'GET', '/api/history/task/'.$task['id']));
        self::assertSame(['delete', 'update', 'create'], array_column($this->json(), 'action'));
    }

    public function test_task_dependencies_stay_inside_one_project_and_cannot_form_a_cycle(): void
    {
        $ids = $this->createClientAndProject();
        $design = $this->task($ids['project'], 'Design')['id'];
        $build = $this->task($ids['project'], 'Build')['id'];
        $test = $this->task($ids['project'], 'Test')['id'];
        self::assertSame(201, $this->callAs('user', 'POST', '/api/projects', json_encode(['title' => 'Other', 'description' => 'd', 'client_id' => $ids['client'], 'status' => 'planned'])));
        $otherProject = $this->json()['id'];
        $foreign = $this->task($otherProject, 'Elsewhere')['id'];
        $put = fn (int $task, array $dependsOn): int => $this->callAs('user', 'PUT', '/api/tasks/'.$task.'/dependencies', json_encode(['depends_on_ids' => $dependsOn]));

        self::assertSame(200, $put($build, [$design]));
        self::assertSame([['id' => $design, 'title' => 'Design']], $this->json());
        self::assertSame(200, $put($test, [$build, $design]));
        self::assertSame(409, $put($design, [$test]), 'design -> build -> test -> design would be a cycle');
        self::assertSame(409, $put($design, [$design]), 'a task cannot depend on itself');
        self::assertInvalid($put($build, [$design, $design]));
        self::assertInvalid($put($build, [$foreign]), 'dependencies stay inside the project');
        self::assertInvalid($put($build, [999]));
        self::assertSame(404, $put(999, [$design]));
        self::assertSame(404, $this->callAs('user', 'GET', '/api/tasks/999/dependencies'));

        $move = ['title' => 'Build', 'description' => 'd', 'project_id' => $otherProject, 'start_datetime' => '2026-03-02T09:00:00+00:00', 'end_datetime' => '2026-03-03T17:00:00+00:00'];
        self::assertSame(409, $this->callAs('user', 'PATCH', '/api/tasks/'.$build, json_encode($move)), 'a task with dependencies cannot change project');
        self::assertSame(200, $put($test, []));
        self::assertSame(200, $put($build, []));
        self::assertSame([], $this->json());
        self::assertSame(200, $this->callAs('user', 'PATCH', '/api/tasks/'.$build, json_encode($move)), $this->lastBody);
        self::assertSame($otherProject, $this->json()['project_id']);
    }

    public function test_projects_are_updated_with_validated_budget_rate_and_manager(): void
    {
        $ids = $this->createClientAndProject();
        $uri = '/api/projects/'.$ids['project'];
        $base = ['title' => 'Build', 'description' => 'd'];

        self::assertSame(200, $this->callAs('user', 'PATCH', $uri, json_encode($base + ['status' => 'on_hold', 'manager_id' => $this->users['manager']->getId(), 'budget_amount' => '5000.00', 'budget_currency' => 'EUR', 'hourly_rate' => '75.00', 'billing_currency' => 'EUR', 'start_date' => '2026-03-01', 'end_date' => '2026-06-30'])), $this->lastBody);
        $project = $this->json();
        self::assertSame('on_hold', $project['status']);
        self::assertSame($this->users['manager']->getId(), $project['manager_id']);
        foreach ([['hourly_rate' => '0.00', 'billing_currency' => 'EUR'], ['budget_amount' => '10.00'], ['budget_amount' => '1.005', 'budget_currency' => 'EUR'], ['start_date' => '2026-07-01', 'end_date' => '2026-06-01'], ['status' => 'paused'], ['title' => ' ']] as $invalid) {
            self::assertInvalid($this->callAs('user', 'PATCH', $uri, json_encode($invalid + $base)), json_encode($invalid));
        }
        self::assertSame(404, $this->callAs('user', 'PATCH', $uri, json_encode($base + ['manager_id' => 999])));
        self::assertSame(404, $this->callAs('user', 'PATCH', $uri, json_encode($base + ['client_id' => 999])));
        self::assertSame(404, $this->callAs('user', 'PATCH', '/api/projects/999', json_encode($base)));
        self::assertSame(404, $this->callAs('user', 'POST', '/api/projects', json_encode($base + ['client_id' => 999])));
        self::assertInvalid($this->callAs('user', 'POST', '/api/projects', '{"title":"No client"}'));

        self::assertSame(200, $this->callAs('user', 'GET', '/api/projects'));
        self::assertCount(1, $this->json());
        self::assertSame(200, $this->callAs('user', 'GET', '/api/projects/managers'));
        self::assertContains('manager@example.test', array_column($this->json(), 'email'));
        self::assertSame(404, $this->callAs('user', 'GET', '/api/projects/999'));
        self::assertSame(404, $this->callAs('user', 'GET', '/api/projects/999/milestones'));
        self::assertSame(404, $this->callAs('user', 'DELETE', '/api/projects/999'));
    }

    public function test_notes_belong_to_their_author_and_feed_the_client_timeline_without_their_content(): void
    {
        $ids = $this->createClientAndProject();
        $notes = '/api/notes/project/'.$ids['project'];

        self::assertInvalid($this->callAs('user', 'POST', $notes, '{"content":" "}'));
        self::assertSame(201, $this->callAs('user', 'POST', $notes, '{"content":"Customer prefers e-mail."}'), $this->lastBody);
        $note = $this->json();
        self::assertSame('Customer prefers e-mail.', $note['content']);
        $uri = $notes.'/'.$note['id'];

        self::assertSame(403, $this->callAs('manager', 'PATCH', $uri, '{"content":"Changed by someone else"}'), 'only the author or an administrator edits a note');
        self::assertSame(403, $this->callAs('finance', 'DELETE', $uri));
        self::assertInvalid($this->callAs('user', 'PATCH', $uri, '{"content":""}'));
        self::assertSame(200, $this->callAs('user', 'PATCH', $uri, '{"content":"Customer prefers phone."}'));
        self::assertSame(404, $this->callAs('user', 'PATCH', '/api/notes/client/'.$ids['client'].'/'.$note['id'], '{"content":"Wrong owner"}'), 'a note is addressed through its own record');
        self::assertSame(200, $this->callAs('finance', 'GET', $notes));
        self::assertSame(['Customer prefers phone.'], array_column($this->json(), 'content'));

        self::assertSame(200, $this->callAs('user', 'GET', '/api/clients/'.$ids['client'].'/activities'));
        $timeline = $this->lastBody;
        self::assertStringContainsString('note', $timeline);
        self::assertStringNotContainsString('prefers', $timeline, 'note content is not copied into the timeline');

        self::assertSame(204, $this->callAs('admin', 'DELETE', $uri), 'administrators may moderate notes');
        self::assertSame(404, $this->callAs('user', 'DELETE', $uri));
    }

    public function test_the_client_timeline_records_manual_entries_and_project_changes(): void
    {
        $ids = $this->createClientAndProject();
        $uri = '/api/clients/'.$ids['client'].'/activities';

        self::assertInvalid($this->callAs('user', 'POST', $uri, '{"type":"call","message":" "}'));
        self::assertSame(404, $this->callAs('user', 'POST', '/api/clients/999/activities', '{"type":"call","message":"Hello"}'));
        self::assertSame(404, $this->callAs('user', 'GET', '/api/clients/999/activities'));
        self::assertSame(201, $this->callAs('user', 'POST', $uri, '{"type":"call","message":"Discussed renewal."}'), $this->lastBody);
        self::assertSame('Discussed renewal.', $this->json()['message']);
        $this->task($ids['project']);

        self::assertSame(200, $this->callAs('finance', 'GET', $uri));
        $messages = implode(' | ', array_column($this->json(), 'message'));
        self::assertStringContainsString('Discussed renewal.', $messages);
        self::assertStringContainsString('Build', $messages, 'creating the project is on the timeline');
        self::assertStringContainsString('Design', $messages, 'creating the task is on the timeline');
    }

    public function test_client_contacts_are_maintained_by_administrators(): void
    {
        $client = $this->createClientAndProject()['client'];
        $uri = '/api/clients/'.$client.'/contacts';

        self::assertSame(403, $this->callAs('user', 'POST', $uri, '{"name":"Ann"}'));
        self::assertInvalid($this->callAs('admin', 'POST', $uri, '{"name":" "}'));
        self::assertInvalid($this->callAs('admin', 'POST', $uri, '{"name":"Ann","email":"not-an-email"}'));
        self::assertSame(404, $this->callAs('admin', 'POST', '/api/clients/999/contacts', '{"name":"Ann"}'));
        self::assertSame(201, $this->callAs('admin', 'POST', $uri, '{"name":"Ann","job_title":"CFO","email":"ann@acme.test","phone":"+371 2000 0000"}'), $this->lastBody);
        $contact = $this->json();
        self::assertSame('CFO', $contact['job_title']);

        self::assertSame(200, $this->callAs('admin', 'PATCH', $uri.'/'.$contact['id'], '{"name":"Ann Smith","job_title":"CEO"}'));
        self::assertSame('Ann Smith', $this->json()['name']);
        self::assertInvalid($this->callAs('admin', 'PATCH', $uri.'/'.$contact['id'], '{"name":""}'));
        self::assertSame(404, $this->callAs('admin', 'PATCH', $uri.'/999', '{"name":"Nobody"}'));
        self::assertSame(200, $this->callAs('user', 'GET', $uri));
        self::assertCount(1, $this->json());
        self::assertSame(404, $this->callAs('user', 'GET', '/api/clients/999/contacts'));
        self::assertSame(403, $this->callAs('finance', 'DELETE', $uri.'/'.$contact['id']));
        self::assertSame(204, $this->callAs('admin', 'DELETE', $uri.'/'.$contact['id']));
        self::assertSame(404, $this->callAs('admin', 'DELETE', $uri.'/'.$contact['id']));
    }

    public function test_news_items_can_only_be_changed_by_their_author(): void
    {
        self::assertInvalid($this->callAs('user', 'POST', '/api/news', '{"title":"No content"}'));
        self::assertSame(201, $this->callAs('user', 'POST', '/api/news', '{"title":"Release","content":"Version 2 is live."}'), $this->lastBody);
        $item = $this->json();
        $uri = '/api/news/'.$item['id'];

        self::assertSame(200, $this->callAs('finance', 'GET', '/api/news'));
        self::assertCount(1, $this->json());
        self::assertSame(200, $this->callAs('finance', 'GET', $uri));
        self::assertSame(404, $this->callAs('finance', 'GET', '/api/news/999'));
        self::assertSame(403, $this->callAs('finance', 'PATCH', $uri, '{"title":"Hijacked","content":"x"}'));
        self::assertSame(403, $this->callAs('admin', 'DELETE', $uri), 'even administrators cannot delete another author\'s item');
        self::assertInvalid($this->callAs('user', 'PATCH', $uri, '{"title":" ","content":"x"}'));
        self::assertSame(200, $this->callAs('user', 'PATCH', $uri, '{"title":"Release 2","content":"Version 2 is live."}'));
        self::assertSame('Release 2', $this->json()['title']);
        self::assertSame(404, $this->callAs('user', 'PATCH', '/api/news/999', '{"title":"x","content":"x"}'));
        self::assertContains($this->callAs('user', 'DELETE', $uri), [200, 204]);
        self::assertSame(404, $this->callAs('user', 'DELETE', $uri));
        self::assertSame(401, $this->callAs(null, 'GET', '/api/news'));
    }
}
