<?php

namespace App\Tests\Functional;

/** Draft invoices: manual lines, approved time and milestones; filters, PDF, issue and the draft-only rules. */
final class InvoiceFlowTest extends ApiTestCase
{
    private const LEDGER = ['accounts_receivable' => ['1100', 'asset'], 'revenue' => ['4000', 'revenue'], 'cash' => ['1000', 'asset']];

    /** @param array<string, mixed> $overrides */
    private function draft(int $projectId, array $overrides = []): array
    {
        $body = $overrides + ['project_id' => $projectId, 'invoice_date' => '2026-03-01', 'due_date' => '2026-03-31', 'currency' => 'EUR', 'items' => [['description' => 'Consulting', 'amount' => '100.00'], ['description' => 'Travel', 'amount' => '20.50']]];
        self::assertSame(201, $this->callAs('user', 'POST', '/api/invoices', json_encode($body)), $this->lastBody);

        return $this->json();
    }

    public function test_a_draft_is_totalled_on_the_server_and_can_be_edited_downloaded_and_deleted(): void
    {
        $ids = $this->createClientAndProject(['manager_id' => $this->users['manager']->getId()]);
        $invoice = $this->draft($ids['project']);

        self::assertSame('120.50', $invoice['total']);
        self::assertSame('draft', $invoice['status']);
        self::assertSame('Acme', $invoice['client_name']);
        self::assertMatchesRegularExpression('/^INV-2026-\d+$/', $invoice['invoice_number']);
        $uri = '/api/invoices/'.$invoice['id'];

        $update = ['project_id' => $ids['project'], 'invoice_date' => '2026-03-02', 'currency' => 'EUR', 'items' => [['description' => 'Consulting', 'amount' => '99.99']]];
        self::assertSame(200, $this->callAs('user', 'PATCH', $uri, json_encode($update)));
        self::assertSame('99.99', $this->json()['total']);
        self::assertSame(404, $this->callAs('user', 'PATCH', '/api/invoices/999', json_encode($update)));
        self::assertSame(200, $this->callAs('user', 'GET', $uri));
        self::assertSame('2026-03-02', substr($this->json()['invoice_date'], 0, 10));
        self::assertSame(404, $this->callAs('user', 'GET', '/api/invoices/999'));

        self::assertSame(200, $this->callAs('user', 'GET', $uri.'/pdf'));
        self::assertSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringStartsWith('%PDF-', $this->lastBody);
        self::assertSame(404, $this->callAs('user', 'GET', '/api/invoices/999/pdf'));

        self::assertSame(204, $this->callAs('user', 'DELETE', $uri));
        self::assertSame(404, $this->callAs('user', 'DELETE', $uri));
    }

    public function test_invoice_input_is_validated(): void
    {
        $ids = $this->createClientAndProject();
        self::assertSame(201, $this->callAs('user', 'POST', '/api/tasks', json_encode(['title' => 'Design', 'description' => 'd', 'project_id' => $ids['project'], 'start_datetime' => '2026-03-02T09:00:00+00:00', 'end_datetime' => '2026-03-03T17:00:00+00:00'])), $this->lastBody);
        $taskId = $this->json()['id'];
        $base = ['project_id' => $ids['project'], 'invoice_date' => '2026-03-01', 'currency' => 'EUR', 'items' => [['description' => 'Work', 'amount' => '10.00']]];
        $invalid = [
            ['items' => []], ['project_id' => 999], ['due_date' => '2026-02-01'], ['invoice_date' => 'not a date'],
            ['items' => [['description' => 'Work']]], ['items' => [['description' => ' ', 'amount' => '1.00']]],
            ['items' => [['description' => 'Work', 'amount' => '1.005']]], ['items' => [['description' => 'Free', 'amount' => '0.00']]],
            ['items' => [['description' => 'x', 'amount' => '1.00', 'task_id' => 999]]],
            ['items' => [['description' => 'x', 'amount' => '1.00', 'task_id' => $taskId], ['description' => 'y', 'amount' => '1.00', 'task_id' => $taskId]]],
        ];
        foreach ($invalid as $fields) {
            self::assertInvalid($this->callAs('user', 'POST', '/api/invoices', json_encode($fields + $base)), json_encode($fields));
        }

        $invoice = $this->draft($ids['project'], ['items' => [['description' => 'ignored', 'amount' => '50.00', 'task_id' => $taskId]]]);
        self::assertSame('Design', $invoice['items'][0]['description'], 'task lines take the task title');
        self::assertInvalid($this->callAs('user', 'PATCH', '/api/invoices/'.$invoice['id'], json_encode(['items' => []] + $base)));
    }

    public function test_invoices_are_filtered_on_the_server(): void
    {
        $ids = $this->createClientAndProject(['manager_id' => $this->users['manager']->getId()]);
        $this->draft($ids['project']);
        $this->draft($ids['project'], ['invoice_date' => '2026-05-10', 'due_date' => null, 'currency' => 'USD', 'items' => [['description' => 'Licence', 'amount' => '900.00']]]);
        $count = function (string $query): int {
            self::assertSame(200, $this->callAs('user', 'GET', '/api/invoices?'.$query), $query.' '.$this->lastBody);

            return count($this->json());
        };

        self::assertSame(2, $count(''));
        self::assertSame(2, $count('client_id='.$ids['client'].'&project_id='.$ids['project'].'&manager_id='.$this->users['manager']->getId()));
        self::assertSame(0, $count('manager_id='.$this->users['finance']->getId()));
        self::assertSame(1, $count('from=2026-05-01&to=2026-05-31'));
        self::assertSame(1, $count('currency=EUR'));
        self::assertSame(1, $count('currency=USD&min=500.00&max=1000.00'));
        self::assertSame(0, $count('currency=EUR&min=500.00'));
        foreach (['client_id=abc', 'from=2026-13-01', 'from=2026-05-01&to=2026-04-01', 'currency=SEK', 'min=10.00', 'currency=EUR&min=1.005'] as $query) {
            self::assertSame(400, $this->callAs('user', 'GET', '/api/invoices?'.$query), $query);
        }
    }

    public function test_approved_billable_time_is_invoiced_once_and_released_when_the_draft_is_deleted(): void
    {
        $project = $this->createClientAndProject(['manager_id' => $this->users['manager']->getId(), 'hourly_rate' => '60.00', 'billing_currency' => 'EUR'])['project'];
        $entries = [];
        foreach ([90, 30] as $minutes) {
            $this->callAs('user', 'POST', '/api/operations/time-entries', json_encode(['project_id' => $project, 'work_date' => '2026-03-02', 'duration_minutes' => $minutes, 'description' => 'Work', 'billable' => true]));
            $entries[] = $this->json()['id'];
        }
        $body = fn (array $ids, array $extra = []): string => json_encode($extra + ['entry_ids' => $ids, 'invoice_date' => '2026-03-31']);

        self::assertSame(409, $this->callAs('user', 'POST', '/api/invoices/from-approved-time', $body($entries)), 'pending time cannot be invoiced');
        foreach ($entries as $id) {
            self::assertSame(200, $this->callAs('manager', 'POST', '/api/operations/time-entries/'.$id.'/decision', '{"decision":"approved"}'));
        }
        self::assertInvalid($this->callAs('user', 'POST', '/api/invoices/from-approved-time', $body([])));
        self::assertInvalid($this->callAs('user', 'POST', '/api/invoices/from-approved-time', $body([$entries[0], $entries[0]])));
        self::assertInvalid($this->callAs('user', 'POST', '/api/invoices/from-approved-time', $body($entries, ['invoice_date' => null])));
        self::assertInvalid($this->callAs('user', 'POST', '/api/invoices/from-approved-time', $body($entries, ['due_date' => '2026-03-01'])));

        self::assertSame(201, $this->callAs('user', 'POST', '/api/invoices/from-approved-time', $body($entries)), $this->lastBody);
        $invoice = $this->json();
        self::assertSame('120.00', $invoice['total'], '120 minutes at 60.00/hour');
        self::assertCount(2, $invoice['items']);
        self::assertSame(409, $this->callAs('user', 'POST', '/api/invoices/from-approved-time', $body($entries)), 'time is invoiced only once');
        self::assertSame(409, $this->callAs('manager', 'POST', '/api/operations/time-entries/'.$entries[0].'/reopen', '{"reason":"typo"}'), 'invoiced time is locked');
        self::assertSame(409, $this->callAs('user', 'DELETE', '/api/operations/time-entries/'.$entries[0]));

        self::assertSame(204, $this->callAs('user', 'DELETE', '/api/invoices/'.$invoice['id']));
        self::assertSame(200, $this->callAs('user', 'GET', '/api/operations/time-entries?project_id='.$project));
        self::assertSame([null, null], array_column($this->json(), 'invoice_id'));
        self::assertSame(201, $this->callAs('user', 'POST', '/api/invoices/from-approved-time', $body([$entries[1]])), 'released time can be invoiced again');
        self::assertSame('30.00', $this->json()['total']);
    }

    public function test_a_completed_priced_milestone_is_invoiced_once_and_released_when_the_draft_is_deleted(): void
    {
        $project = $this->createClientAndProject()['project'];
        $milestones = '/api/projects/'.$project.'/milestones';
        self::assertInvalid($this->callAs('user', 'POST', $milestones, '{"title":" "}'));
        self::assertInvalid($this->callAs('user', 'POST', $milestones, '{"title":"Design","amount":"500.00"}'), 'an amount needs a currency');
        self::assertInvalid($this->callAs('user', 'POST', $milestones, '{"title":"Design","due_date":"soon"}'));
        self::assertSame(404, $this->callAs('user', 'POST', '/api/projects/999/milestones', '{"title":"Design"}'));
        self::assertSame(201, $this->callAs('user', 'POST', $milestones, '{"title":"Design","description":"Phase 1","due_date":"2026-04-01","amount":"500.00","currency":"EUR"}'), $this->lastBody);
        $priced = $this->json()['id'];
        self::assertSame(201, $this->callAs('user', 'POST', $milestones, '{"title":"Kick-off","description":"Meeting"}'));
        $unpriced = $this->json()['id'];
        $from = fn (int $id, string $body = '{"invoice_date":"2026-04-02","due_date":"2026-05-02"}'): int => $this->callAs('user', 'POST', '/api/invoices/from-milestone/'.$id, $body);

        self::assertSame(409, $from($priced), 'the milestone must be completed first');
        self::assertSame(200, $this->callAs('user', 'POST', '/api/projects/milestones/'.$priced.'/complete'));
        self::assertSame(200, $this->callAs('user', 'POST', '/api/projects/milestones/'.$unpriced.'/complete'));
        self::assertSame(404, $this->callAs('user', 'POST', '/api/projects/milestones/999/complete'));
        self::assertSame(409, $from($unpriced), 'an unpriced milestone cannot be invoiced');
        self::assertSame(404, $from(999));
        self::assertInvalid($from($priced, '{}'));
        self::assertInvalid($from($priced, '{"invoice_date":"2026-04-02","due_date":"2026-04-01"}'));

        self::assertSame(201, $from($priced), $this->lastBody);
        $invoice = $this->json();
        self::assertSame('500.00', $invoice['total']);
        self::assertSame('Milestone: Design', $invoice['items'][0]['description']);
        self::assertSame(409, $from($priced), 'a milestone is invoiced only once');
        self::assertSame(409, $this->callAs('user', 'POST', '/api/projects/milestones/'.$priced.'/complete'), 'an invoiced milestone is frozen');
        self::assertSame(200, $this->callAs('user', 'GET', $milestones));
        self::assertEquals([$priced => 'invoiced', $unpriced => 'completed'], array_column($this->json(), 'status', 'id'));

        self::assertSame(204, $this->callAs('user', 'DELETE', '/api/invoices/'.$invoice['id']));
        self::assertSame(201, $from($priced), 'discarding the draft makes the milestone billable again');
    }

    public function test_issuing_needs_a_configured_ledger_and_freezes_the_invoice(): void
    {
        $project = $this->createClientAndProject()['project'];
        $invoice = $this->draft($project);
        $uri = '/api/invoices/'.$invoice['id'];

        self::assertSame(409, $this->callAs('finance', 'POST', $uri.'/issue'), 'no mapped accounts or open period yet');
        $this->openLedger(self::LEDGER);
        $late = $this->draft($project, ['invoice_date' => '2027-01-15', 'due_date' => null]);
        self::assertSame(409, $this->callAs('finance', 'POST', '/api/invoices/'.$late['id'].'/issue'), 'the invoice date is outside any open period');

        self::assertSame(200, $this->callAs('finance', 'POST', $uri.'/issue'), $this->lastBody);
        self::assertSame('issued', $this->json()['status']);
        self::assertSame('120.50', $this->json()['balance_due']);
        self::assertSame(409, $this->callAs('finance', 'POST', $uri.'/issue'), 'an invoice is issued once');
        self::assertSame(409, $this->callAs('user', 'PATCH', $uri, json_encode(['project_id' => $project, 'invoice_date' => '2026-03-01', 'currency' => 'EUR', 'items' => [['description' => 'x', 'amount' => '1.00']]])), 'issued invoices are immutable');
        self::assertSame(409, $this->callAs('user', 'DELETE', $uri));

        self::assertSame(200, $this->callAs('finance', 'GET', '/api/finance/reports/reconciliation'));
        self::assertTrue($this->json()['reconciled'], $this->lastBody);
        self::assertSame(200, $this->callAs('finance', 'GET', '/api/finance/reports/receivables-payables?currency=EUR'), $this->lastBody);
    }
}
