<?php

namespace App\Tests\Functional;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Time approval and correction, expense approval and reimbursement postings, profitability and documents. */
final class OperationsFlowTest extends ApiTestCase
{
    private const LEDGER = [
        'accounts_receivable' => ['1100', 'asset'], 'revenue' => ['4000', 'revenue'], 'cash' => ['1000', 'asset'],
        'project_expense' => ['5000', 'expense'], 'expense_payable' => ['2100', 'liability'],
    ];

    /** @return array{client: int, project: int} a project managed by the "manager" user, billed at 60.00 EUR/hour */
    private function managedProject(): array
    {
        return $this->createClientAndProject([
            'manager_id' => $this->users['manager']->getId(), 'hourly_rate' => '60.00', 'billing_currency' => 'EUR',
            'budget_amount' => '1000.00', 'budget_currency' => 'EUR',
        ]);
    }

    private function logTime(string $as, int $projectId, int $minutes = 90, bool $billable = true, string $date = '2026-03-02'): array
    {
        $body = ['project_id' => $projectId, 'work_date' => $date, 'duration_minutes' => $minutes, 'description' => 'Work', 'billable' => $billable];
        self::assertSame(201, $this->callAs($as, 'POST', '/api/operations/time-entries', json_encode($body)), $this->lastBody);

        return $this->json();
    }

    public function test_time_is_priced_from_rate_snapshots_and_approved_by_the_manager_only(): void
    {
        self::assertSame(200, $this->callAs('admin', 'PUT', '/api/operations/users/'.$this->users['user']->getId().'/cost-rate', '{"hourly_rate":"40.00","currency":"EUR"}'));
        self::assertSame('40.00', $this->json()['hourly_cost_rate']);
        $project = $this->managedProject()['project'];

        $entry = $this->logTime('user', $project);
        self::assertSame('pending_approval', $entry['approval_status']);
        self::assertSame('90.00', $entry['billing_amount'], '90 minutes at 60.00/hour');
        self::assertNull($entry['cost_amount'], 'cost is hidden from regular users');
        $uri = '/api/operations/time-entries/'.$entry['id'];

        self::assertSame(403, $this->callAs('user', 'POST', $uri.'/decision', '{"decision":"approved"}'), 'authors cannot approve their own time');
        self::assertSame(403, $this->callAs('finance', 'POST', $uri.'/decision', '{"decision":"approved"}'), 'only the manager or an administrator decides');
        self::assertContains($this->callAs('manager', 'POST', $uri.'/decision', '{"decision":"maybe"}'), [400, 422]);
        self::assertSame(200, $this->callAs('manager', 'POST', $uri.'/decision', '{"decision":"approved","comment":"ok"}'));
        self::assertSame('approved', $this->json()['approval_status']);
        self::assertSame(409, $this->callAs('manager', 'POST', $uri.'/decision', '{"decision":"rejected"}'), 'a decision is final until reopened');
        self::assertSame(404, $this->callAs('manager', 'POST', '/api/operations/time-entries/999/decision', '{"decision":"approved"}'));

        self::assertSame(200, $this->callAs('finance', 'GET', '/api/operations/time-entries?project_id='.$project));
        self::assertSame('60.00', $this->json()[0]['cost_amount'], 'finance sees the 40.00/hour cost snapshot');
        self::assertInvalid($this->callAs('user', 'GET', '/api/operations/time-entries'));
        self::assertInvalid($this->callAs('user', 'GET', '/api/operations/time-entries?project_id=abc'));
        self::assertSame(404, $this->callAs('user', 'GET', '/api/operations/time-entries?project_id=999'));
    }

    public function test_time_entry_input_is_validated(): void
    {
        $ids = $this->createClientAndProject();
        $base = ['project_id' => $ids['project'], 'work_date' => '2026-03-02', 'duration_minutes' => 60, 'description' => 'Work'];

        self::assertInvalid($this->callAs('user', 'POST', '/api/operations/time-entries', '{"description":"x"}'));
        self::assertSame(404, $this->callAs('user', 'POST', '/api/operations/time-entries', json_encode(['project_id' => 999] + $base)));
        self::assertContains($this->callAs('user', 'POST', '/api/operations/time-entries', json_encode(['work_date' => '2026-02-30'] + $base)), [400, 422]);
        self::assertContains($this->callAs('user', 'POST', '/api/operations/time-entries', json_encode(['duration_minutes' => 1441] + $base)), [400, 422]);
        self::assertInvalid($this->callAs('user', 'POST', '/api/operations/time-entries', json_encode(['task_id' => 999] + $base)));
        self::assertSame(409, $this->callAs('user', 'POST', '/api/operations/time-entries', json_encode(['billable' => true] + $base)), 'billable time needs a project rate');
        self::assertSame(201, $this->callAs('user', 'POST', '/api/operations/time-entries', json_encode($base)));
        self::assertNull($this->json()['billing_amount']);
    }

    public function test_time_corrections_follow_the_withdraw_and_reopen_rules(): void
    {
        $project = $this->managedProject()['project'];
        $pending = $this->logTime('user', $project)['id'];
        $approved = $this->logTime('user', $project, 30)['id'];
        $this->callAs('manager', 'POST', '/api/operations/time-entries/'.$approved.'/decision', '{"decision":"approved"}');

        self::assertSame(403, $this->callAs('finance', 'DELETE', '/api/operations/time-entries/'.$pending), 'only the author or an administrator withdraws');
        self::assertSame(409, $this->callAs('user', 'DELETE', '/api/operations/time-entries/'.$approved), 'approved time must be reopened first');
        self::assertSame(204, $this->callAs('user', 'DELETE', '/api/operations/time-entries/'.$pending));
        self::assertSame(404, $this->callAs('user', 'DELETE', '/api/operations/time-entries/'.$pending));

        self::assertSame(403, $this->callAs('user', 'POST', '/api/operations/time-entries/'.$approved.'/reopen', '{"reason":"typo"}'));
        self::assertInvalid($this->callAs('manager', 'POST', '/api/operations/time-entries/'.$approved.'/reopen', '{"reason":" "}'));
        self::assertSame(200, $this->callAs('manager', 'POST', '/api/operations/time-entries/'.$approved.'/reopen', '{"reason":"Wrong day"}'));
        self::assertSame('pending_approval', $this->json()['approval_status']);
        self::assertSame(409, $this->callAs('manager', 'POST', '/api/operations/time-entries/'.$approved.'/reopen', '{"reason":"again"}'), 'only approved time can be reopened');

        self::assertSame(200, $this->callAs('user', 'GET', '/api/operations/time-entries/'.$approved.'/history'));
        self::assertSame(['approved', 'reopened'], array_column($this->json(), 'action'));
        self::assertSame('Wrong day', $this->json()[1]['comment']);
        self::assertSame(404, $this->callAs('user', 'GET', '/api/operations/time-entries/999/history'));
    }

    public function test_approving_and_reimbursing_an_expense_posts_balanced_journals(): void
    {
        $this->openLedger(self::LEDGER);
        $project = $this->managedProject()['project'];
        $body = ['project_id' => $project, 'description' => 'Train', 'amount' => '120.00', 'currency' => 'EUR', 'expense_date' => '2026-03-03', 'category' => 'travel'];
        self::assertSame(201, $this->callAs('user', 'POST', '/api/operations/expenses', json_encode($body)), $this->lastBody);
        $expense = $this->json();
        self::assertSame('pending_approval', $expense['status']);
        $uri = '/api/operations/expenses/'.$expense['id'];

        self::assertSame(200, $this->callAs('manager', 'GET', '/api/operations/expenses/pending-approval'));
        self::assertCount(1, $this->json());
        self::assertSame(200, $this->callAs('finance', 'GET', '/api/operations/expenses/pending-approval'));
        self::assertSame([], $this->json(), 'finance users do not approve expenses of projects they do not manage');
        self::assertSame(200, $this->callAs('manager', 'GET', '/api/operations/expenses'));
        self::assertSame([], $this->json(), 'regular users only list their own expenses');

        self::assertInvalid($this->callAs('finance', 'POST', $uri.'/reimbursements', '{"amount":"10.00","paid_at":"2026-03-05"}'), 'only approved expenses are reimbursed');
        self::assertSame(403, $this->callAs('user', 'POST', $uri.'/decision', '{"decision":"approved"}'));
        self::assertContains($this->callAs('manager', 'POST', $uri.'/decision', '{"decision":"later"}'), [400, 422]);
        self::assertSame(200, $this->callAs('manager', 'POST', $uri.'/decision', '{"decision":"approved","comment":"Agreed"}'), $this->lastBody);
        self::assertSame('approved', $this->json()['status']);
        self::assertSame('Agreed', $this->json()['approvals'][0]['comment']);
        self::assertSame(409, $this->callAs('manager', 'POST', $uri.'/decision', '{"decision":"rejected"}'));

        self::assertSame(403, $this->callAs('user', 'POST', $uri.'/reimbursements', '{"amount":"50.00","paid_at":"2026-03-05"}'));
        self::assertInvalid($this->callAs('finance', 'POST', $uri.'/reimbursements', '{"amount":"0.00","paid_at":"2026-03-05"}'));
        self::assertContains($this->callAs('finance', 'POST', $uri.'/reimbursements', '{"amount":"50.00","paid_at":"05/03/2026"}'), [400, 422]);
        self::assertSame(409, $this->callAs('finance', 'POST', $uri.'/reimbursements', '{"amount":"50.00","paid_at":"2027-01-05"}'), 'no open fiscal period');
        self::assertSame(201, $this->callAs('finance', 'POST', $uri.'/reimbursements', '{"amount":"50.00","paid_at":"2026-03-05","reference":"BT-1"}'), $this->lastBody);
        self::assertSame(409, $this->callAs('finance', 'POST', $uri.'/reimbursements', '{"amount":"70.01","paid_at":"2026-03-06"}'), 'cannot reimburse more than is outstanding');
        self::assertSame(201, $this->callAs('finance', 'POST', $uri.'/reimbursements', '{"amount":"70.00","paid_at":"2026-03-06"}'));

        self::assertSame(200, $this->callAs('user', 'GET', $uri.'/reimbursements'));
        self::assertSame(['70.00', '50.00'], array_column($this->json(), 'amount'), 'newest first');
        self::assertSame(403, $this->callAs('inventory', 'GET', $uri.'/reimbursements'), 'unrelated users cannot see reimbursements');
        self::assertSame(200, $this->callAs('user', 'GET', '/api/operations/expenses?status=approved&project_id='.$project));
        self::assertSame('0.00', $this->json()[0]['balance_to_reimburse']);
        self::assertInvalid($this->callAs('user', 'GET', '/api/operations/expenses?status=paid'));

        self::assertSame(200, $this->callAs('finance', 'GET', '/api/finance/reports/subledger-reconciliation?currency=EUR'));
        self::assertTrue($this->json()['reconciled'], $this->lastBody);
        self::assertSame(200, $this->callAs('finance', 'GET', '/api/finance/reports/trial-balance?currency=EUR'));
        self::assertTrue($this->json()['balanced']);
    }

    public function test_expense_input_and_posting_preconditions_are_checked(): void
    {
        $project = $this->managedProject()['project'];
        $base = ['project_id' => $project, 'description' => 'Hotel', 'amount' => '80.00', 'currency' => 'EUR', 'expense_date' => '2026-03-03'];
        foreach ([['amount' => '0.00'], ['amount' => '1.999'], ['currency' => 'SEK'], ['expense_date' => '2026-13-01'], ['category' => 'bribes'], ['description' => ' ']] as $invalid) {
            self::assertContains($this->callAs('user', 'POST', '/api/operations/expenses', json_encode($invalid + $base)), [400, 422], json_encode($invalid));
        }
        self::assertSame(404, $this->callAs('user', 'POST', '/api/operations/expenses', json_encode(['project_id' => 999] + $base)));
        self::assertSame(201, $this->callAs('user', 'POST', '/api/operations/expenses', json_encode($base)));
        $id = $this->json()['id'];
        self::assertSame('other', $this->json()['category']);

        self::assertSame(409, $this->callAs('admin', 'POST', '/api/operations/expenses/'.$id.'/decision', '{"decision":"approved"}'), 'approval needs mapped accounts and an open period');
        self::assertSame(200, $this->callAs('admin', 'POST', '/api/operations/expenses/'.$id.'/decision', '{"decision":"rejected","comment":"Not billable"}'));
        self::assertSame('rejected', $this->json()['status']);
        self::assertSame(404, $this->callAs('admin', 'POST', '/api/operations/expenses/999/decision', '{"decision":"approved"}'));
        self::assertSame(404, $this->callAs('finance', 'GET', '/api/operations/expenses/999/reimbursements'));
    }

    public function test_profitability_uses_approved_time_and_expenses_within_the_period(): void
    {
        $this->openLedger(self::LEDGER);
        $this->callAs('admin', 'PUT', '/api/operations/users/'.$this->users['user']->getId().'/cost-rate', '{"hourly_rate":"40.00","currency":"EUR"}');
        $project = $this->managedProject()['project'];
        foreach (['2026-03-02', '2026-04-10'] as $date) {
            $this->callAs('manager', 'POST', '/api/operations/time-entries/'.$this->logTime('user', $project, 60, true, $date)['id'].'/decision', '{"decision":"approved"}');
        }
        $this->logTime('user', $project, 45, true, '2026-03-04');
        $this->callAs('user', 'POST', '/api/operations/expenses', json_encode(['project_id' => $project, 'description' => 'Train', 'amount' => '25.00', 'currency' => 'EUR', 'expense_date' => '2026-03-03']));
        $this->callAs('manager', 'POST', '/api/operations/expenses/'.$this->json()['id'].'/decision', '{"decision":"approved"}');
        $uri = '/api/operations/projects/'.$project.'/profitability';

        self::assertSame(200, $this->callAs('finance', 'GET', $uri.'?currency=EUR'));
        $all = $this->json();
        self::assertSame(120, $all['approved_minutes']);
        self::assertSame(45, $all['pending_approval_minutes']);
        self::assertSame('120.00', $all['approved_billable_revenue']);
        self::assertSame('80.00', $all['approved_labor_cost']);
        self::assertSame('25.00', $all['approved_expense_cost']);
        self::assertSame('15.00', $all['gross_margin']);
        self::assertSame('895.00', $all['budget_variance']);

        self::assertSame(200, $this->callAs('finance', 'GET', $uri.'?currency=EUR&from=2026-03-01&to=2026-03-31'));
        self::assertSame('60.00', $this->json()['approved_billable_revenue']);
        self::assertSame('-5.00', $this->json()['gross_margin']);
        self::assertNull($this->json()['budget_variance'], 'the whole-project budget is not prorated to a period');
        self::assertSame(200, $this->callAs('finance', 'GET', $uri.'?currency=USD'));
        self::assertSame(2, $this->json()['unpriced_billable_entries']);
        self::assertSame(1, $this->json()['excluded_expenses_other_currency']);

        self::assertInvalid($this->callAs('finance', 'GET', $uri.'?currency=SEK'));
        self::assertInvalid($this->callAs('finance', 'GET', $uri.'?currency=EUR&from=2026-04-01&to=2026-03-01'));
        self::assertInvalid($this->callAs('finance', 'GET', $uri.'?currency=EUR&from=March'));
    }

    public function test_cost_rates_are_validated(): void
    {
        $uri = '/api/operations/users/'.$this->users['user']->getId().'/cost-rate';
        self::assertSame(404, $this->callAs('admin', 'PUT', '/api/operations/users/999/cost-rate', '{"hourly_rate":"40.00","currency":"EUR"}'));
        self::assertInvalid($this->callAs('admin', 'PUT', $uri, '{"hourly_rate":"40.00"}'));
        self::assertContains($this->callAs('admin', 'PUT', $uri, '{"hourly_rate":"40.00","currency":"SEK"}'), [400, 422]);
        self::assertContains($this->callAs('admin', 'PUT', $uri, '{"hourly_rate":"40.005","currency":"EUR"}'), [400, 422]);
        self::assertSame(200, $this->callAs('admin', 'PUT', $uri, '{"hourly_rate":"40.00","currency":"EUR"}'));
        self::assertSame(200, $this->callAs('admin', 'PUT', $uri, '{}'));
        self::assertNull($this->json()['hourly_cost_rate']);
    }

    public function test_project_documents_are_stored_downloaded_and_deleted_by_their_uploader(): void
    {
        $project = $this->createClientAndProject()['project'];
        $source = tempnam(sys_get_temp_dir(), 'crm-doc');
        file_put_contents($source, "Statement of work\n");
        $upload = fn (string $as, ?UploadedFile $file): int => $this->upload($as, '/api/operations/projects/'.$project.'/documents', $file);

        self::assertInvalid($upload('user', null));
        self::assertSame(201, $upload('user', new UploadedFile($source, 'sow.txt', 'text/plain', null, true)), $this->lastBody);
        $document = $this->json();
        self::assertSame('sow.txt', $document['original_name']);
        self::assertSame('text/plain', $document['mime_type']);
        try {
            self::assertSame(200, $this->callAs('finance', 'GET', '/api/operations/projects/'.$project.'/documents'));
            self::assertCount(1, $this->json());
            self::assertSame(200, $this->callAs('finance', 'GET', '/api/operations/documents/'.$document['id'].'/download'));
            self::assertStringContainsString('sow.txt', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
            self::assertSame(409, $this->callAs('user', 'DELETE', '/api/projects/'.$project), 'a project with documents cannot be deleted');
            self::assertSame(403, $this->callAs('finance', 'DELETE', '/api/operations/documents/'.$document['id']));
        } finally {
            self::assertSame(204, $this->callAs('user', 'DELETE', '/api/operations/documents/'.$document['id']));
        }
        self::assertSame(404, $this->callAs('user', 'GET', '/api/operations/documents/'.$document['id'].'/download'));
        self::assertSame(404, $this->callAs('user', 'GET', '/api/operations/projects/999/documents'));

        $binary = tempnam(sys_get_temp_dir(), 'crm-bin');
        file_put_contents($binary, random_bytes(64));
        self::assertInvalid($upload('user', new UploadedFile($binary, 'tool.exe', 'application/octet-stream', null, true)), 'unsupported types are refused');
        @unlink($source);
        @unlink($binary);
    }

    private function upload(string $as, string $uri, ?UploadedFile $file): int
    {
        static::ensureKernelShutdown();
        $this->client = static::createClient();
        $managed = static::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class)->find(\App\Entity\User::class, $this->users[$as]->getId());
        $this->client->loginUser($managed, 'api');
        $this->client->request('POST', $uri, files: $file === null ? [] : ['file' => $file]);
        $this->lastBody = (string) $this->client->getResponse()->getContent();

        return $this->client->getResponse()->getStatusCode();
    }
}
