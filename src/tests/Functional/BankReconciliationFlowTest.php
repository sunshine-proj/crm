<?php

namespace App\Tests\Functional;

/** Bank account setup, statement import, matching and reconciliation over HTTP. */
final class BankReconciliationFlowTest extends ApiTestCase
{
    private int $invoiceId;
    private string $invoiceNumber;
    private int $bankAccountId;

    private function camt(string $entries, string $opening = '1000.00', string $closing = '1055.00'): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02"><BkToCstmrStmt><Stmt>'
            .'<Acct><Id><IBAN>LV80BANK0000435195001</IBAN></Id></Acct>'
            .'<Bal><Tp><CdOrPrtry><Cd>OPBD</Cd></CdOrPrtry></Tp><Amt Ccy="EUR">'.$opening.'</Amt><CdtDbtInd>CRDT</CdtDbtInd></Bal>'
            .'<Bal><Tp><CdOrPrtry><Cd>CLBD</Cd></CdOrPrtry></Tp><Amt Ccy="EUR">'.$closing.'</Amt><CdtDbtInd>CRDT</CdtDbtInd></Bal>'
            .$entries.'</Stmt></BkToCstmrStmt></Document>';
    }

    private function entry(string $direction, string $amount, string $date, string $text): string
    {
        return '<Ntry><Amt Ccy="EUR">'.$amount.'</Amt><CdtDbtInd>'.$direction.'</CdtDbtInd><Sts>BOOK</Sts><BookgDt><Dt>'.$date.'</Dt></BookgDt>'
            .'<NtryDtls><TxDtls><RmtInf><Ustrd>'.$text.'</Ustrd></RmtInf></TxDtls></NtryDtls></Ntry>';
    }

    private function setUpBooks(): void
    {
        $this->callAs('admin', 'POST', '/api/finance/periods', '{"name":"2026","starts_on":"2026-01-01","ends_on":"2026-12-31"}');
        $ids = [];
        foreach (['accounts_receivable' => ['1200', 'asset'], 'revenue' => ['4000', 'revenue'], 'cash' => ['1000', 'asset'], 'accounts_payable' => ['2000', 'liability'], 'project_expense' => ['5000', 'expense']] as $role => [$code, $type]) {
            $this->callAs('admin', 'POST', '/api/finance/accounts', json_encode(['code' => $code, 'name' => $role, 'type' => $type]));
            $ids[$role] = $this->json()['id'];
        }
        $this->callAs('admin', 'PUT', '/api/finance/account-mappings', json_encode($ids));
        $this->callAs('admin', 'POST', '/api/clients', '{"title":"Acme","notes":"n"}');
        $client = $this->json()['id'];
        $this->callAs('admin', 'POST', '/api/projects', json_encode(['title' => 'Site', 'description' => 'd', 'client_id' => $client, 'status' => 'active']));
        $project = $this->json()['id'];
        $this->callAs('finance', 'POST', '/api/invoices', json_encode(['project_id' => $project, 'invoice_date' => '2026-03-01', 'currency' => 'EUR', 'items' => [['description' => 'Work', 'amount' => '100.00']]]));
        $this->invoiceId = $this->json()['id'];
        $this->callAs('finance', 'POST', '/api/invoices/'.$this->invoiceId.'/issue');
        $this->invoiceNumber = $this->json()['invoice_number'];
        self::assertSame(201, $this->callAs('finance', 'POST', '/api/invoices/'.$this->invoiceId.'/payments', '{"amount":"100.00","paid_at":"2026-03-05","reference":"wire"}'));

        self::assertSame(201, $this->callAs('finance', 'POST', '/api/finance/payables', '{"vendor_name":"Landlord","vendor_reference":"RENT-3","currency":"EUR","total":"40.00","bill_date":"2026-03-02"}'));
        $billId = $this->json()['id'];
        self::assertSame(201, $this->callAs('finance', 'POST', '/api/finance/payables/'.$billId.'/payments', '{"amount":"40.00","paid_at":"2026-03-06","reference":"rent"}'));

        self::assertSame(201, $this->callAs('finance', 'POST', '/api/bank/accounts', '{"name":"Main EUR","iban":"lv80 bank 0000 4351 95001","currency":"EUR","ledger_account_id":'.$ids['cash'].'}'));
        $this->bankAccountId = $this->json()['id'];
        self::assertSame('LV80BANK0000435195001', $this->json()['iban']);
    }

    private function importStatement(): array
    {
        $xml = $this->camt(
            $this->entry('CRDT', '100.00', '2026-03-05', 'Payment '.$this->invoiceNumber)
            .$this->entry('DBIT', '40.00', '2026-03-06', 'Rent RENT-3')
            .$this->entry('DBIT', '5.00', '2026-03-07', 'Account fee'),
        );
        self::assertSame(201, $this->callAs('finance', 'POST', '/api/bank/accounts/'.$this->bankAccountId.'/imports', json_encode(['filename' => 'march.xml', 'format' => 'camt053', 'content' => $xml])));

        return $this->json();
    }

    public function test_bank_data_is_for_finance_users_only(): void
    {
        self::assertSame(403, $this->callAs('user', 'GET', '/api/bank/accounts'));
        self::assertSame(200, $this->callAs('finance', 'GET', '/api/bank/accounts'));
        self::assertSame(401, $this->callAs(null, 'GET', '/api/bank/accounts'));
    }

    public function test_a_statement_is_imported_once_and_its_balances_must_add_up(): void
    {
        $this->setUpBooks();
        $import = $this->importStatement();

        self::assertSame(3, $import['imported_lines']);
        self::assertSame('1000.00', $import['opening_balance']);
        self::assertSame('1055.00', $import['closing_balance']);
        self::assertSame('2026-03-05', $import['period_from']);

        $xml = $this->camt($this->entry('CRDT', '100.00', '2026-03-05', 'Payment '.$this->invoiceNumber).$this->entry('DBIT', '40.00', '2026-03-06', 'Rent RENT-3').$this->entry('DBIT', '5.00', '2026-03-07', 'Account fee'));
        self::assertSame(409, $this->callAs('finance', 'POST', '/api/bank/accounts/'.$this->bankAccountId.'/imports', json_encode(['filename' => 'again.xml', 'format' => 'camt053', 'content' => $xml])), 'same file twice');

        $bad = $this->camt($this->entry('CRDT', '10.00', '2026-03-09', 'Extra'), '1000.00', '1055.00');
        self::assertSame(409, $this->callAs('finance', 'POST', '/api/bank/accounts/'.$this->bankAccountId.'/imports', json_encode(['filename' => 'bad.xml', 'format' => 'camt053', 'content' => $bad])), 'balances must add up');
        self::assertSame(400, $this->callAs('finance', 'POST', '/api/bank/accounts/'.$this->bankAccountId.'/imports', json_encode(['filename' => 'x.xml', 'format' => 'camt053', 'content' => '<!DOCTYPE x [<!ENTITY a "b">]><Document/>'])));
    }

    public function test_overlapping_statements_do_not_duplicate_transactions(): void
    {
        $this->setUpBooks();
        $this->importStatement();
        $overlap = $this->camt($this->entry('DBIT', '5.00', '2026-03-07', 'Account fee').$this->entry('CRDT', '2.00', '2026-03-08', 'Interest'), '1060.00', '1057.00');

        self::assertSame(201, $this->callAs('finance', 'POST', '/api/bank/accounts/'.$this->bankAccountId.'/imports', json_encode(['filename' => 'overlap.xml', 'format' => 'camt053', 'content' => $overlap])));
        self::assertSame(1, $this->json()['imported_lines']);
        self::assertSame(1, $this->json()['skipped_duplicates']);
        $this->callAs('finance', 'GET', '/api/bank/accounts/'.$this->bankAccountId.'/lines');
        self::assertCount(4, $this->json());
    }

    public function test_lines_are_matched_to_recorded_payments_and_the_period_reconciles(): void
    {
        $this->setUpBooks();
        $this->importStatement();

        self::assertSame(200, $this->callAs('finance', 'POST', '/api/bank/accounts/'.$this->bankAccountId.'/auto-match'));
        self::assertSame(['matched' => 2, 'left_open' => 1], $this->json(), 'receipt and rent are matched by reference; the fee has no book record');

        $this->callAs('finance', 'GET', '/api/bank/accounts/'.$this->bankAccountId.'/reconciliation?from=2026-03-01&to=2026-03-31');
        $report = $this->json();
        self::assertFalse($report['reconciled']);
        self::assertSame(1, $report['statement_lines']['unmatched']);
        self::assertSame([], $report['recorded_receipts_without_bank_line']);
        self::assertSame('Account fee', $report['unmatched_lines'][0]['reference']);

        self::assertSame(200, $this->callAs('finance', 'POST', '/api/bank/lines/'.$report['unmatched_lines'][0]['id'].'/ignore', '{"reason":"Bank fee, expensed from the statement"}'));
        $this->callAs('finance', 'GET', '/api/bank/accounts/'.$this->bankAccountId.'/reconciliation?from=2026-03-01&to=2026-03-31');
        self::assertTrue($this->json()['reconciled']);
        self::assertSame(1, $this->json()['statement_lines']['ignored']);
    }

    public function test_manual_matching_requires_the_same_amount_and_a_free_payment(): void
    {
        $this->setUpBooks();
        $this->importStatement();
        $this->callAs('finance', 'GET', '/api/bank/accounts/'.$this->bankAccountId.'/lines');
        $lines = array_column($this->json(), null, 'reference');
        $receiptLine = $lines['Payment '.$this->invoiceNumber];
        $rentLine = $lines['Rent RENT-3'];

        $this->callAs('finance', 'GET', '/api/bank/lines/'.$receiptLine['id'].'/suggestions');
        $suggestion = $this->json()[0];
        self::assertSame('customer_payment', $suggestion['type']);
        self::assertGreaterThanOrEqual(90, $suggestion['score']);

        self::assertSame(409, $this->callAs('finance', 'POST', '/api/bank/lines/'.$rentLine['id'].'/match', json_encode(['type' => 'customer_payment', 'id' => $suggestion['id']])), 'money out cannot match a receipt');
        self::assertSame(200, $this->callAs('finance', 'POST', '/api/bank/lines/'.$receiptLine['id'].'/match', json_encode(['type' => 'customer_payment', 'id' => $suggestion['id']])));
        self::assertSame('matched', $this->json()['state']);
        self::assertSame(409, $this->callAs('finance', 'POST', '/api/bank/lines/'.$receiptLine['id'].'/match', json_encode(['type' => 'customer_payment', 'id' => $suggestion['id']])), 'already matched');

        self::assertSame(200, $this->callAs('finance', 'POST', '/api/bank/lines/'.$receiptLine['id'].'/unmatch'));
        self::assertSame('unmatched', $this->json()['state']);
        self::assertSame(200, $this->callAs('finance', 'POST', '/api/bank/lines/'.$receiptLine['id'].'/match', json_encode(['type' => 'customer_payment', 'id' => $suggestion['id']])));
        self::assertContains($this->callAs('finance', 'POST', '/api/bank/lines/'.$rentLine['id'].'/ignore', '{"reason":""}'), [400, 422]);
    }

    public function test_a_payment_recorded_without_a_bank_line_is_reported(): void
    {
        $this->setUpBooks();
        $xml = $this->camt($this->entry('DBIT', '40.00', '2026-03-06', 'Rent RENT-3'), '1000.00', '960.00');
        $this->callAs('finance', 'POST', '/api/bank/accounts/'.$this->bankAccountId.'/imports', json_encode(['filename' => 'rent.xml', 'format' => 'camt053', 'content' => $xml]));
        $this->callAs('finance', 'POST', '/api/bank/accounts/'.$this->bankAccountId.'/auto-match');

        $this->callAs('finance', 'GET', '/api/bank/accounts/'.$this->bankAccountId.'/reconciliation?from=2026-03-01&to=2026-03-31');
        $report = $this->json();
        self::assertFalse($report['reconciled']);
        self::assertCount(1, $report['recorded_receipts_without_bank_line']);
        self::assertSame('100.00', $report['recorded_receipts_without_bank_line'][0]['amount']);
        self::assertSame(400, $this->callAs('finance', 'GET', '/api/bank/accounts/'.$this->bankAccountId.'/reconciliation?from=2026-04-01&to=2026-03-01'));
    }

    public function test_csv_statements_import_in_the_account_currency(): void
    {
        $this->setUpBooks();
        $csv = "date,amount,description,name\n2026-03-05,100.00,Payment ".$this->invoiceNumber.",Acme\n";
        self::assertSame(201, $this->callAs('finance', 'POST', '/api/bank/accounts/'.$this->bankAccountId.'/imports', json_encode(['filename' => 'march.csv', 'format' => 'csv', 'content' => $csv])));
        self::assertSame(1, $this->json()['imported_lines']);
        self::assertSame(409, $this->callAs('finance', 'POST', '/api/bank/accounts/'.$this->bankAccountId.'/imports', json_encode(['filename' => 'gbp.csv', 'format' => 'csv', 'content' => "date,amount,currency\n2026-03-05,1.00,GBP\n"])), 'currency must match the account');
    }
}
