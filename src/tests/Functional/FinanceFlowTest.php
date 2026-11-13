<?php

namespace App\Tests\Functional;

/** General ledger over HTTP: accounts, periods, manual journals, reversals, vendor bills and the reports. */
final class FinanceFlowTest extends ApiTestCase
{
    private const LEDGER = [
        'accounts_receivable' => ['1100', 'asset'], 'revenue' => ['4000', 'revenue'], 'cash' => ['1000', 'asset'],
        'accounts_payable' => ['2000', 'liability'], 'project_expense' => ['5000', 'expense'], 'expense_payable' => ['2100', 'liability'],
    ];

    /** @param list<array{0: int, 1: int, 2: int}> $lines [account id, debit minor, credit minor] */
    private function journal(array $lines, array $overrides = []): string
    {
        return json_encode($overrides + [
            'entry_date' => '2026-02-01', 'description' => 'Owner funding', 'currency' => 'EUR',
            'lines' => array_map(static fn (array $line): array => ['account_id' => $line[0], 'debit_minor' => $line[1], 'credit_minor' => $line[2], 'memo' => 'm'], $lines),
        ]);
    }

    public function test_the_chart_of_accounts_is_validated_and_frozen_once_posted(): void
    {
        $accounts = $this->openLedger(self::LEDGER);
        self::assertSame(409, $this->callAs('admin', 'POST', '/api/finance/accounts', '{"code":"1000","name":"Duplicate","type":"asset"}'));
        self::assertInvalid($this->callAs('admin', 'POST', '/api/finance/accounts', '{"code":"9000","name":"Odd","type":"mystery"}'));
        self::assertInvalid($this->callAs('admin', 'POST', '/api/finance/accounts', '{"code":" ","name":"Blank","type":"asset"}'));
        self::assertSame(404, $this->callAs('admin', 'PATCH', '/api/finance/accounts/999', '{"code":"1","name":"x","type":"asset"}'));
        self::assertSame(409, $this->callAs('admin', 'PATCH', '/api/finance/accounts/'.$accounts['cash'], '{"code":"4000","name":"Clash","type":"asset"}'));
        self::assertSame(200, $this->callAs('admin', 'PATCH', '/api/finance/accounts/'.$accounts['cash'], '{"code":"1000","name":"Bank","type":"asset"}'));
        self::assertSame('Bank', $this->json()['name']);

        self::assertSame(201, $this->callAs('finance', 'POST', '/api/finance/journal-entries', $this->journal([[$accounts['cash'], 50000, 0], [$accounts['revenue'], 0, 50000]])), $this->lastBody);
        self::assertSame(409, $this->callAs('admin', 'PATCH', '/api/finance/accounts/'.$accounts['cash'], '{"code":"1000","name":"Renamed","type":"asset"}'), 'posted accounts keep code, name and type');
        self::assertSame(200, $this->callAs('admin', 'PATCH', '/api/finance/accounts/'.$accounts['cash'], '{"code":"1000","name":"Bank","type":"asset","active":false}'), 'but can be deactivated');
        self::assertInvalid($this->callAs('finance', 'POST', '/api/finance/journal-entries', $this->journal([[$accounts['cash'], 100, 0], [$accounts['revenue'], 0, 100]])), 'inactive accounts take no postings');

        self::assertSame(200, $this->callAs('finance', 'GET', '/api/finance/accounts'));
        self::assertCount(6, $this->json());
        self::assertSame(200, $this->callAs('finance', 'GET', '/api/finance/account-mappings'));
        self::assertSame(200, $this->callAs('user', 'GET', '/api/finance/currencies'));
    }

    public function test_fiscal_periods_cannot_overlap_and_closing_blocks_postings_until_reopened(): void
    {
        $accounts = $this->openLedger(self::LEDGER);
        self::assertSame(409, $this->callAs('admin', 'POST', '/api/finance/periods', '{"name":"Q1","starts_on":"2026-01-01","ends_on":"2026-03-31"}'), 'overlaps 2026');
        self::assertSame(409, $this->callAs('admin', 'POST', '/api/finance/periods', '{"name":"2026","starts_on":"2028-01-01","ends_on":"2028-12-31"}'), 'names are unique');
        self::assertInvalid($this->callAs('admin', 'POST', '/api/finance/periods', '{"name":"Bad","starts_on":"2027-12-31","ends_on":"2027-01-01"}'));
        self::assertInvalid($this->callAs('admin', 'POST', '/api/finance/periods', '{"name":"Bad","starts_on":"01.01.2027","ends_on":"2027-12-31"}'));
        self::assertSame(200, $this->callAs('finance', 'GET', '/api/finance/periods'));
        $period = $this->json()[0];
        self::assertFalse($period['closed']);
        $uri = '/api/finance/periods/'.$period['id'];
        $entry = $this->journal([[$accounts['cash'], 1000, 0], [$accounts['revenue'], 0, 1000]]);

        self::assertInvalid($this->callAs('admin', 'POST', $uri.'/close', '{"reason":" "}'));
        self::assertSame(404, $this->callAs('admin', 'POST', '/api/finance/periods/999/close', '{"reason":"Year end"}'));
        self::assertSame(409, $this->callAs('admin', 'POST', $uri.'/reopen', '{"reason":"Not closed"}'), 'only a closed period can be reopened');
        self::assertSame(200, $this->callAs('admin', 'POST', $uri.'/close', '{"reason":"Year end"}'), $this->lastBody);
        self::assertTrue($this->json()['closed']);
        self::assertSame(409, $this->callAs('admin', 'POST', $uri.'/close', '{"reason":"Again"}'));
        self::assertSame(409, $this->callAs('finance', 'POST', '/api/finance/journal-entries', $entry), 'no postings into a closed period');

        self::assertInvalid($this->callAs('admin', 'POST', $uri.'/reopen', '{}'));
        self::assertSame(404, $this->callAs('admin', 'POST', '/api/finance/periods/999/reopen', '{"reason":"Correction"}'));
        self::assertSame(200, $this->callAs('admin', 'POST', $uri.'/reopen', '{"reason":"Late supplier bill"}'));
        self::assertFalse($this->json()['closed']);
        self::assertSame(201, $this->callAs('finance', 'POST', '/api/finance/journal-entries', $entry));
    }

    public function test_manual_journals_must_balance_and_reversals_mirror_the_original(): void
    {
        $accounts = $this->openLedger(self::LEDGER);
        $cash = $accounts['cash'];
        $revenue = $accounts['revenue'];
        $invalid = [
            $this->journal([[$cash, 1000, 0]]),
            $this->journal([[$cash, 1000, 0], [$revenue, 0, 900]]),
            $this->journal([[$cash, 1000, 1000], [$revenue, 0, 0]]),
            $this->journal([[$cash, 1000, 0], [999, 0, 1000]]),
            $this->journal([[$cash, 1000, 0], [$revenue, 0, 1000]], ['currency' => 'SEK']),
            $this->journal([[$cash, 1000, 0], [$revenue, 0, 1000]], ['description' => ' ']),
            $this->journal([[$cash, 1000, 0], [$revenue, 0, 1000]], ['entry_date' => 'yesterday']),
            json_encode(['entry_date' => '2026-02-01', 'description' => 'x', 'currency' => 'EUR', 'lines' => [['account_id' => $cash, 'debit_minor' => '10.00', 'credit_minor' => 0, 'memo' => 'm'], ['account_id' => $revenue, 'debit_minor' => 0, 'credit_minor' => 1000, 'memo' => 'm']]]),
        ];
        foreach ($invalid as $body) {
            self::assertInvalid($this->callAs('finance', 'POST', '/api/finance/journal-entries', $body), $body);
        }
        self::assertSame(409, $this->callAs('finance', 'POST', '/api/finance/journal-entries', $this->journal([[$cash, 1000, 0], [$revenue, 0, 1000]], ['entry_date' => '2027-02-01'])), 'no period for the date');

        self::assertSame(201, $this->callAs('admin', 'POST', '/api/finance/opening-balances', $this->journal([[$cash, 250000, 0], [$accounts['accounts_payable'], 0, 250000]], ['entry_date' => '2026-01-01', 'description' => 'Opening'])), $this->lastBody);
        self::assertSame(201, $this->callAs('finance', 'POST', '/api/finance/journal-entries', $this->journal([[$cash, 1000, 0], [$revenue, 0, 1000]])), $this->lastBody);
        $entry = $this->json();
        self::assertMatchesRegularExpression('/^JE-2026-\d{6}$/', $entry['entry_number']);
        $uri = '/api/finance/journal-entries/'.$entry['id'].'/reverse';

        self::assertInvalid($this->callAs('admin', 'POST', $uri, '{"entry_date":"2026-02-02"}'), 'a reason is required');
        self::assertInvalid($this->callAs('admin', 'POST', $uri, '{"entry_date":"02/02/2026","reason":"Wrong account"}'));
        self::assertSame(409, $this->callAs('admin', 'POST', $uri, '{"entry_date":"2027-02-02","reason":"Wrong account"}'));
        self::assertSame(404, $this->callAs('admin', 'POST', '/api/finance/journal-entries/999/reverse', '{"entry_date":"2026-02-02","reason":"x"}'));
        self::assertContains($this->callAs('admin', 'POST', $uri, '{"entry_date":"2026-02-02","reason":"Wrong account"}'), [200, 201], $this->lastBody);
        self::assertSame(409, $this->callAs('admin', 'POST', $uri, '{"entry_date":"2026-02-03","reason":"Twice"}'), 'an entry is reversed once');

        self::assertSame(200, $this->callAs('finance', 'GET', '/api/finance/journal-entries'));
        self::assertCount(3, $this->json());
        self::assertSame(200, $this->callAs('finance', 'GET', '/api/finance/journal-entries?from=2026-02-02&to=2026-02-28&currency=EUR'));
        self::assertCount(1, $this->json(), 'only the reversal is dated after 1 February');
        self::assertSame(400, $this->callAs('finance', 'GET', '/api/finance/journal-entries?from=2026-03-01&to=2026-02-01'));
        self::assertSame(400, $this->callAs('finance', 'GET', '/api/finance/journal-entries?currency=SEK'));

        self::assertSame(200, $this->callAs('finance', 'GET', '/api/finance/reports/trial-balance?currency=EUR'));
        $trial = $this->json();
        self::assertTrue($trial['balanced']);
        self::assertSame($trial['total_debits'], $trial['total_credits']);
        $balances = array_column($trial['accounts'], 'balance', 'code');
        self::assertEqualsWithDelta(0.0, (float) ($balances['4000'] ?? '0.00'), 0.001, 'the reversal cancels the revenue posting');
        self::assertSame(400, $this->callAs('finance', 'GET', '/api/finance/reports/trial-balance'), 'a currency is required');
    }

    public function test_vendor_bills_are_paid_within_their_balance_and_reports_stay_balanced(): void
    {
        $this->openLedger(self::LEDGER);
        $bill = ['vendor_name' => 'Hosting Ltd', 'vendor_reference' => 'H-1', 'currency' => 'EUR', 'total' => '300.00', 'bill_date' => '2026-03-01', 'due_date' => '2026-03-31'];
        foreach ([['total' => '0.00'], ['total' => '1.005'], ['currency' => 'SEK'], ['bill_date' => '1 March'], ['due_date' => '2026-02-01'], ['vendor_name' => ' ']] as $invalid) {
            self::assertInvalid($this->callAs('finance', 'POST', '/api/finance/payables', json_encode($invalid + $bill)), json_encode($invalid));
        }
        self::assertSame(409, $this->callAs('finance', 'POST', '/api/finance/payables', json_encode(['bill_date' => '2027-03-01', 'due_date' => null] + $bill)), 'no open period');
        self::assertSame(403, $this->callAs('user', 'POST', '/api/finance/payables', json_encode($bill)));
        self::assertSame(201, $this->callAs('finance', 'POST', '/api/finance/payables', json_encode($bill)), $this->lastBody);
        $created = $this->json();
        self::assertSame('300.00', $created['balance_due']);
        self::assertSame(409, $this->callAs('finance', 'POST', '/api/finance/payables', json_encode($bill)), 'vendor references are unique');
        $uri = '/api/finance/payables/'.$created['id'].'/payments';

        self::assertInvalid($this->callAs('finance', 'POST', $uri, '{"amount":"0.00","paid_at":"2026-03-10"}'));
        self::assertInvalid($this->callAs('finance', 'POST', $uri, '{"amount":"10.00","paid_at":"10 March"}'));
        self::assertSame(409, $this->callAs('finance', 'POST', $uri, '{"amount":"300.01","paid_at":"2026-03-10"}'), 'no overpayment');
        self::assertSame(409, $this->callAs('finance', 'POST', $uri, '{"amount":"10.00","paid_at":"2027-03-10"}'));
        self::assertSame(404, $this->callAs('finance', 'POST', '/api/finance/payables/999/payments', '{"amount":"10.00","paid_at":"2026-03-10"}'));
        self::assertSame(201, $this->callAs('finance', 'POST', $uri, '{"amount":"120.00","paid_at":"2026-03-10","reference":"BT-9"}'), $this->lastBody);
        self::assertSame(201, $this->callAs('finance', 'POST', $uri, '{"amount":"180.00","paid_at":"2026-03-12"}'));
        self::assertSame(409, $this->callAs('finance', 'POST', $uri, '{"amount":"0.01","paid_at":"2026-03-13"}'));
        self::assertSame(200, $this->callAs('finance', 'GET', '/api/finance/payables'));
        self::assertSame('0.00', $this->json()[0]['balance_due']);
        self::assertSame('300.00', $this->json()[0]['paid_total']);

        self::assertSame(200, $this->callAs('finance', 'GET', '/api/finance/reports/financial-statements?currency=EUR&from=2026-01-01&to=2026-12-31'), $this->lastBody);
        $statements = $this->json();
        self::assertSame('-300.00', $statements['profit_and_loss']['net_income']);
        self::assertTrue($statements['balance_sheet']['balanced']);
        self::assertSame('300.00', $statements['cash_flow']['cash_outflows']);
        self::assertSame(400, $this->callAs('finance', 'GET', '/api/finance/reports/financial-statements'));
        self::assertSame(400, $this->callAs('finance', 'GET', '/api/finance/reports/financial-statements?currency=EUR&from=2026-13-01'));

        self::assertSame(200, $this->callAs('finance', 'GET', '/api/finance/reports/receivables-payables?currency=EUR'));
        self::assertSame(400, $this->callAs('finance', 'GET', '/api/finance/reports/receivables-payables'));
        self::assertSame(200, $this->callAs('finance', 'GET', '/api/finance/reports/reconciliation'));
        self::assertTrue($this->json()['reconciled'], $this->lastBody);
        self::assertSame(200, $this->callAs('finance', 'GET', '/api/finance/reports/subledger-reconciliation?currency=EUR'));
        self::assertTrue($this->json()['reconciled'], $this->lastBody);
        self::assertSame('0.00', $this->json()['payables']['difference']);
        self::assertSame(400, $this->callAs('finance', 'GET', '/api/finance/reports/subledger-reconciliation'));
    }

    public function test_tax_codes_are_administrator_configuration(): void
    {
        self::assertSame(403, $this->callAs('finance', 'POST', '/api/finance/tax-codes', '{"code":"STD","name":"Standard","rate_percent":"21.00"}'));
        self::assertSame(201, $this->callAs('admin', 'POST', '/api/finance/tax-codes', '{"code":"std","name":"Standard","rate_percent":"21.00"}'), $this->lastBody);
        self::assertSame('21.00', $this->json()['rate_percent']);
        self::assertSame(409, $this->callAs('admin', 'POST', '/api/finance/tax-codes', json_encode(['code' => $this->json()['code'], 'name' => 'Again', 'rate_percent' => '5.00'])));
        foreach (['{"code":"X","name":"X","rate_percent":"-1.00"}', '{"code":"X","name":"X","rate_percent":"101.00"}', '{"code":" ","name":"X","rate_percent":"5.00"}', '{"code":"X","name":"X","rate_percent":"5.005"}'] as $body) {
            self::assertInvalid($this->callAs('admin', 'POST', '/api/finance/tax-codes', $body), $body);
        }
        self::assertSame(200, $this->callAs('finance', 'GET', '/api/finance/tax-codes'));
        self::assertCount(1, $this->json());
    }
}
