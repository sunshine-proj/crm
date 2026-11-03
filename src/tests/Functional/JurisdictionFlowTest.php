<?php

namespace App\Tests\Functional;

use App\Entity\Client;

/** Jurisdiction sign-off gating, tax on invoices, gapless numbering and credit notes over HTTP. */
final class JurisdictionFlowTest extends ApiTestCase
{
    private int $projectId;

    private function setUpLedger(): void
    {
        $this->callAs('admin', 'PUT', '/api/settings', '{"country_code":"LV","default_currency":"EUR","retention_years":10}');
        $this->callAs('admin', 'POST', '/api/finance/periods', '{"name":"2026","starts_on":"2026-01-01","ends_on":"2026-12-31"}');
        $ids = [];
        foreach (['accounts_receivable' => ['1200', 'asset'], 'revenue' => ['4000', 'revenue'], 'cash' => ['1000', 'asset'], 'tax_payable' => ['2200', 'liability']] as $role => [$code, $type]) {
            $this->callAs('admin', 'POST', '/api/finance/accounts', json_encode(['code' => $code, 'name' => $role, 'type' => $type]));
            $ids[$role] = json_decode($this->lastBody, true)['id'] ?? self::fail($role.': '.substr($this->lastBody, 0, 500));
        }
        self::assertSame(200, $this->callAs('admin', 'PUT', '/api/finance/account-mappings', json_encode($ids)));
        $this->callAs('admin', 'POST', '/api/clients', '{"title":"Acme","notes":"n","legal_name":"Acme SIA","vat_number":"LV40003123456"}');
        $client = $this->json()['id'];
        $this->callAs('admin', 'POST', '/api/projects', json_encode(['title' => 'Site', 'description' => 'd', 'client_id' => $client, 'status' => 'active']));
        $this->projectId = json_decode($this->lastBody, true)['id'] ?? self::fail(substr($this->lastBody, 0, 400));
    }

    private function activateRules(): void
    {
        self::assertSame(201, $this->callAs('admin', 'POST', '/api/jurisdiction/standard-tax-code'));
        $base = ['country_code' => 'LV', 'adviser_name' => 'A. Berzins', 'adviser_firm' => 'Berzins Tax', 'reference' => 'Opinion 2026/14', 'validated_on' => '2026-02-01'];
        self::assertSame(201, $this->callAs('admin', 'POST', '/api/jurisdiction/validations', json_encode($base + ['scope' => 'tax', 'rounding' => 'per_rate_group'])));
        self::assertSame(201, $this->callAs('admin', 'POST', '/api/jurisdiction/validations', json_encode($base + ['scope' => 'numbering'])));
        self::assertSame(200, $this->callAs('admin', 'PUT', '/api/jurisdiction/legal-profile', json_encode(['legal_name' => 'My Company SIA', 'vat_number' => 'LV40001000001', 'address' => 'Brivibas 1, Riga', 'payment_instructions' => 'IBAN LV00BANK0000000000000'])));
    }

    /** @return array<string, mixed> */
    private function draft(string $first = '100.00', string $second = '50.00'): array
    {
        self::assertSame(201, $this->callAs('finance', 'POST', '/api/invoices', json_encode(['project_id' => $this->projectId, 'invoice_date' => '2026-03-01', 'due_date' => '2026-03-31', 'currency' => 'EUR', 'items' => [['description' => 'Work', 'amount' => $first], ['description' => 'Hosting', 'amount' => $second]]])));

        return $this->json();
    }

    public function test_nothing_is_taxed_or_numbered_sequentially_before_a_sign_off(): void
    {
        $this->setUpLedger();
        $invoice = $this->draft();

        self::assertSame('150.00', $invoice['total']);
        self::assertSame('0.00', $invoice['tax_total']);
        self::assertSame([], $invoice['tax_breakdown']);
        self::assertMatchesRegularExpression('/^INV-2026-\d{6}$/', $invoice['invoice_number']);

        $this->callAs('finance', 'GET', '/api/jurisdiction');
        self::assertFalse($this->json()['tax_active']);
        self::assertFalse($this->json()['numbering_active']);
    }

    public function test_only_administrators_record_and_revoke_sign_offs_and_each_needs_its_evidence(): void
    {
        $this->setUpLedger();
        $body = json_encode(['country_code' => 'LV', 'scope' => 'tax', 'adviser_name' => 'A. Berzins', 'reference' => 'Opinion', 'validated_on' => '2026-02-01', 'rounding' => 'per_line']);
        self::assertSame(403, $this->callAs('finance', 'POST', '/api/jurisdiction/validations', $body));
        self::assertContains($this->callAs('admin', 'POST', '/api/jurisdiction/validations', json_encode(['rounding' => null, 'reference' => ''] + json_decode($body, true))), [400, 422]);
        self::assertSame(400, $this->callAs('admin', 'POST', '/api/jurisdiction/validations', json_encode(['rounding' => null] + json_decode($body, true))), 'tax sign-off needs the rounding method');
        self::assertSame(400, $this->callAs('admin', 'POST', '/api/jurisdiction/validations', json_encode(['validated_on' => '2999-01-01'] + json_decode($body, true))), 'no future-dated sign-off');
        self::assertSame(201, $this->callAs('admin', 'POST', '/api/jurisdiction/validations', $body));
        $id = $this->json()['id'];
        self::assertSame(409, $this->callAs('admin', 'POST', '/api/jurisdiction/validations', $body), 'one active sign-off per country and scope');
        self::assertSame(403, $this->callAs('finance', 'POST', '/api/jurisdiction/validations/'.$id.'/revoke', '{"reason":"x"}'));
        self::assertSame(200, $this->callAs('admin', 'POST', '/api/jurisdiction/validations/'.$id.'/revoke', '{"reason":"Adviser withdrew"}'));
        self::assertFalse($this->json()['active']);
        self::assertSame(409, $this->callAs('admin', 'POST', '/api/jurisdiction/validations/'.$id.'/revoke', '{"reason":"again"}'));
    }

    public function test_validated_rules_add_tax_and_sequential_numbers_at_issue(): void
    {
        $this->setUpLedger();
        $this->activateRules();
        $first = $this->draft();

        self::assertSame('150.00', $first['net_total']);
        self::assertSame('31.50', $first['tax_total'], '21% of 150.00');
        self::assertSame('181.50', $first['total']);
        self::assertSame('LV-STD', $first['tax_breakdown'][0]['tax_code']);
        self::assertMatchesRegularExpression('/^DRAFT-\d{6}$/', $first['invoice_number'], 'drafts stay out of the sequence');

        self::assertSame(200, $this->callAs('finance', 'POST', '/api/invoices/'.$first['id'].'/issue'));
        self::assertSame('INV-LV-2026-000001', $this->json()['invoice_number']);
        $second = $this->draft('10.00', '0.00');
        self::assertSame(200, $this->callAs('finance', 'POST', '/api/invoices/'.$second['id'].'/issue'));
        self::assertSame('INV-LV-2026-000002', $this->json()['invoice_number']);
        self::assertSame('12.10', $this->json()['total']);
    }

    public function test_numbers_are_gapless_when_a_draft_is_discarded_and_follow_the_year(): void
    {
        $this->setUpLedger();
        $this->activateRules();
        $a = $this->draft();
        $discarded = $this->draft();
        $b = $this->draft();
        self::assertSame(204, $this->callAs('finance', 'DELETE', '/api/invoices/'.$discarded['id']));

        $this->callAs('finance', 'POST', '/api/invoices/'.$a['id'].'/issue');
        self::assertSame('INV-LV-2026-000001', $this->json()['invoice_number']);
        $this->callAs('finance', 'POST', '/api/invoices/'.$b['id'].'/issue');
        self::assertSame('INV-LV-2026-000002', $this->json()['invoice_number'], 'the discarded draft left no hole');
    }

    public function test_issuing_requires_the_organisation_legal_profile_once_rules_are_on(): void
    {
        $this->setUpLedger();
        self::assertSame(201, $this->callAs('admin', 'POST', '/api/jurisdiction/standard-tax-code'));
        $this->callAs('admin', 'POST', '/api/jurisdiction/validations', json_encode(['country_code' => 'LV', 'scope' => 'tax', 'adviser_name' => 'A', 'reference' => 'R', 'validated_on' => '2026-02-01', 'rounding' => 'per_line']));
        $invoice = $this->draft();

        self::assertSame(409, $this->callAs('finance', 'POST', '/api/invoices/'.$invoice['id'].'/issue'));
        self::assertStringContainsString('legal name', $this->json()['error']);
    }

    public function test_a_credit_note_reverses_revenue_and_tax_and_reduces_what_is_owed(): void
    {
        $this->setUpLedger();
        $this->activateRules();
        $invoice = $this->draft('100.00', '0.00');
        $this->callAs('finance', 'POST', '/api/invoices/'.$invoice['id'].'/issue');
        self::assertSame('121.00', $this->json()['balance_due']);

        self::assertSame(403, $this->callAs('user', 'POST', '/api/invoices/'.$invoice['id'].'/credit-notes', '{"reason":"x"}'));
        self::assertContains($this->callAs('finance', 'POST', '/api/invoices/'.$invoice['id'].'/credit-notes', '{"reason":""}'), [400, 422]);
        self::assertSame(400, $this->callAs('finance', 'POST', '/api/invoices/'.$invoice['id'].'/credit-notes', json_encode(['reason' => 'Discount', 'credit_date' => '2026-03-10', 'items' => [['description' => 'Discount', 'amount' => '10.00', 'tax_code' => 'NOPE']]])), 'only the invoice\'s own tax codes');

        self::assertSame(201, $this->callAs('finance', 'POST', '/api/invoices/'.$invoice['id'].'/credit-notes', json_encode(['reason' => 'Goodwill discount', 'credit_date' => '2026-03-10', 'items' => [['description' => 'Discount', 'amount' => '10.00', 'tax_code' => 'LV-STD']]])));
        $note = $this->json();
        self::assertSame('CN-LV-2026-000001', $note['credit_note_number']);
        self::assertSame(['10.00', '2.10', '12.10'], [$note['net_total'], $note['tax_total'], $note['total']]);

        $this->callAs('finance', 'GET', '/api/invoices/'.$invoice['id']);
        self::assertSame('12.10', $this->json()['credited_total']);
        self::assertSame('108.90', $this->json()['balance_due']);
        self::assertSame('issued', $this->json()['status']);

        // Over-crediting: more net than invoiced at the rate.
        self::assertSame(409, $this->callAs('finance', 'POST', '/api/invoices/'.$invoice['id'].'/credit-notes', json_encode(['reason' => 'Too much', 'credit_date' => '2026-03-11', 'items' => [['description' => 'Rest', 'amount' => '95.00', 'tax_code' => 'LV-STD']]])));
        // Whole-invoice credit is only for invoices without earlier credit notes.
        self::assertSame(409, $this->callAs('finance', 'POST', '/api/invoices/'.$invoice['id'].'/credit-notes', json_encode(['reason' => 'Cancel', 'credit_date' => '2026-03-11'])));

        // The rest of the invoice is credited line by line; the invoice is then settled by credit alone.
        self::assertSame(201, $this->callAs('finance', 'POST', '/api/invoices/'.$invoice['id'].'/credit-notes', json_encode(['reason' => 'Cancelled', 'credit_date' => '2026-03-12', 'items' => [['description' => 'Remaining work', 'amount' => '90.00', 'tax_code' => 'LV-STD']]])));
        $this->callAs('finance', 'GET', '/api/invoices/'.$invoice['id']);
        self::assertSame('credited', $this->json()['status']);
        self::assertSame('0.00', $this->json()['balance_due']);

        $this->callAs('finance', 'GET', '/api/finance/reports/subledger-reconciliation?currency=EUR');
        self::assertTrue($this->json()['reconciled'], $this->lastBody);
        self::assertSame('0.00', $this->json()['receivables']['subledger']);
        self::assertSame('0.00', $this->json()['tax_payable']['ledger']);
    }

    public function test_money_already_received_cannot_be_credited_away(): void
    {
        $this->setUpLedger();
        $this->activateRules();
        $invoice = $this->draft('100.00', '0.00');
        $this->callAs('finance', 'POST', '/api/invoices/'.$invoice['id'].'/issue');
        self::assertSame(201, $this->callAs('finance', 'POST', '/api/invoices/'.$invoice['id'].'/payments', '{"amount":"100.00","paid_at":"2026-03-05"}'));

        self::assertSame(409, $this->callAs('finance', 'POST', '/api/invoices/'.$invoice['id'].'/credit-notes', json_encode(['reason' => 'Return', 'credit_date' => '2026-03-10', 'items' => [['description' => 'Return', 'amount' => '50.00', 'tax_code' => 'LV-STD']]])), '60.50 credit exceeds the 21.00 still owed');
        self::assertSame(201, $this->callAs('finance', 'POST', '/api/invoices/'.$invoice['id'].'/credit-notes', json_encode(['reason' => 'Small', 'credit_date' => '2026-03-10', 'items' => [['description' => 'Small', 'amount' => '10.00', 'tax_code' => 'LV-STD']]])));
        self::assertSame('12.10', $this->json()['total']);
        $this->callAs('finance', 'GET', '/api/invoices/'.$invoice['id']);
        self::assertSame('partially_paid', $this->json()['status']);
        self::assertSame('8.90', $this->json()['balance_due']);
        self::assertSame(409, $this->callAs('finance', 'POST', '/api/invoices/'.$invoice['id'].'/payments', '{"amount":"8.91","paid_at":"2026-03-06"}'), 'receipts are capped by the credited balance');
        self::assertSame(201, $this->callAs('finance', 'POST', '/api/invoices/'.$invoice['id'].'/payments', '{"amount":"8.90","paid_at":"2026-03-06"}'));
        $this->callAs('finance', 'GET', '/api/invoices/'.$invoice['id']);
        self::assertSame('paid', $this->json()['status']);
    }

    public function test_credit_note_and_invoice_pdfs_are_served_to_finance_users(): void
    {
        $this->setUpLedger();
        $this->activateRules();
        $invoice = $this->draft('100.00', '0.00');
        $this->callAs('finance', 'POST', '/api/invoices/'.$invoice['id'].'/issue');
        $this->callAs('finance', 'POST', '/api/invoices/'.$invoice['id'].'/credit-notes', json_encode(['reason' => 'Discount', 'credit_date' => '2026-03-10', 'items' => [['description' => 'Discount', 'amount' => '10.00', 'tax_code' => 'LV-STD']]]));
        $noteId = $this->json()['id'];

        self::assertSame(200, $this->callAs('finance', 'GET', '/api/credit-notes/'.$noteId.'/pdf'));
        self::assertStringStartsWith('%PDF-', $this->lastBody);
        self::assertSame(403, $this->callAs('user', 'GET', '/api/credit-notes/'.$noteId.'/pdf'));
        self::assertSame(200, $this->callAs('finance', 'GET', '/api/invoices/'.$invoice['id'].'/pdf'));
        self::assertStringStartsWith('%PDF-', $this->lastBody);
    }

    public function test_revoking_the_numbering_sign_off_returns_to_id_based_numbers(): void
    {
        $this->setUpLedger();
        $this->activateRules();
        $this->callAs('admin', 'GET', '/api/jurisdiction/validations');
        $numbering = array_values(array_filter($this->json(), static fn (array $v): bool => $v['scope'] === 'numbering'))[0];
        self::assertSame(200, $this->callAs('admin', 'POST', '/api/jurisdiction/validations/'.$numbering['id'].'/revoke', '{"reason":"Format under review"}'));
        $invoice = $this->draft();
        $this->callAs('finance', 'POST', '/api/invoices/'.$invoice['id'].'/issue');

        self::assertMatchesRegularExpression('/^INV-2026-\d{6}$/', $this->json()['invoice_number']);
        self::assertSame('181.50', $this->json()['total'], 'tax stays on while its own sign-off stands');
    }
}
