<?php

namespace App\Tests\Functional;

/** Organization structure API: administrators build it, everyone reads reporting lines, users manage their own deputies. */
final class OrgStructureFlowTest extends ApiTestCase
{
    /** @return array<string, int> ids of the units and positions created */
    private function buildStructure(): array
    {
        $ids = [];
        foreach ([['ACME', 'Acme', 'organization', null], ['SALES', 'Sales', 'unit', 'ACME'], ['EAST', 'East', 'section', 'SALES']] as [$code, $name, $kind, $parent]) {
            $body = ['code' => $code, 'name' => $name, 'kind' => $kind] + ($parent !== null ? ['parent_id' => $ids[$parent]] : []);
            self::assertSame(201, $this->callAs('admin', 'POST', '/api/org/units', json_encode($body)), $this->lastBody);
            $ids[$code] = $this->json()['id'];
        }
        foreach ([['ceo', 'ACME', 'Director', true], ['head', 'SALES', 'Head of Sales', true], ['rep', 'SALES', 'Sales rep', false], ['east', 'EAST', 'Account manager', false]] as [$key, $unit, $title, $isHead]) {
            self::assertSame(201, $this->callAs('admin', 'POST', '/api/org/positions', json_encode(['unit_id' => $ids[$unit], 'title' => $title, 'is_head' => $isHead])), $this->lastBody);
            $ids['pos_'.$key] = $this->json()['id'];
        }
        foreach ([['admin', 'pos_ceo'], ['manager', 'pos_head'], ['user', 'pos_rep'], ['finance', 'pos_east']] as [$user, $position]) {
            self::assertSame(201, $this->callAs('admin', 'POST', '/api/org/employments', json_encode(['user_id' => $this->users[$user]->getId(), 'position_id' => $ids[$position], 'starts_on' => '2026-01-01'])), $this->lastBody);
            $ids['emp_'.$user] = $this->json()['id'];
        }

        return $ids;
    }

    public function test_administrators_build_the_structure_and_everyone_sees_reporting_lines(): void
    {
        $ids = $this->buildStructure();

        self::assertSame(200, $this->callAs('user', 'GET', '/api/org/me'));
        $me = $this->json();
        self::assertSame('Sales rep', $me['user']['position_title']);
        self::assertSame(['Acme', 'Sales'], array_column($me['unit_path'], 'name'));
        self::assertSame(['manager@example.test', 'admin@example.test'], array_column($me['superiors'], 'email'));
        self::assertSame([], $me['managed_unit_ids']);

        self::assertSame(200, $this->callAs('user', 'GET', '/api/org/users/'.$this->users['manager']->getId().'/reporting-line'));
        $line = $this->json();
        self::assertSame(['finance@example.test', 'user@example.test'], array_column($line['direct_subordinates'], 'email'), 'EAST has no head, so its staff report to the head of Sales.');
        self::assertSame(2, $line['subordinate_count']);
        self::assertSame([$ids['SALES'], $ids['EAST']], $line['managed_unit_ids']);
        self::assertSame(404, $this->callAs('user', 'GET', '/api/org/users/99999/reporting-line'));

        self::assertSame(200, $this->callAs('user', 'GET', '/api/org/units'));
        $units = array_column($this->json(), null, 'code');
        self::assertSame('manager@example.test', $units['SALES']['head']['email']);
        self::assertSame(2, $units['SALES']['member_count']);
        self::assertSame(3, $units['EAST']['depth']);

        self::assertSame(200, $this->callAs('user', 'GET', '/api/org/units/'.$ids['SALES'].'/members?include_subunits=1'));
        self::assertSame(['finance@example.test', 'manager@example.test', 'user@example.test'], array_column($this->json(), 'email'));
        self::assertSame(200, $this->callAs('user', 'GET', '/api/org/units/'.$ids['SALES'].'/members'));
        self::assertCount(2, $this->json());
        self::assertSame(404, $this->callAs('user', 'GET', '/api/org/units/99999/members'));

        self::assertSame(200, $this->callAs('user', 'GET', '/api/org/positions?unit_id='.$ids['SALES']));
        self::assertSame(['Head of Sales', 'Sales rep'], array_column($this->json(), 'title'));
        self::assertSame('manager@example.test', $this->json()[0]['holders'][0]['email']);
        self::assertSame(400, $this->callAs('user', 'GET', '/api/org/positions?unit_id=abc'));

        self::assertSame(200, $this->callAs('user', 'GET', '/api/org/employments?unit_id='.$ids['SALES']));
        self::assertCount(2, $this->json());
        self::assertSame(400, $this->callAs('user', 'GET', '/api/org/employments?user_id=0'));

        self::assertSame(200, $this->callAs('user', 'GET', '/api/org/people'));
        $people = array_column($this->json(), null, 'email');
        self::assertSame('East', $people['finance@example.test']['unit_name']);
        self::assertNull($people['inventory@example.test']['position_title']);

        // Changes are versioned like other master data.
        self::assertSame(200, $this->callAs('user', 'GET', '/api/history/org_unit/'.$ids['SALES']));
        self::assertNotEmpty($this->json());
    }

    public function test_only_administrators_change_the_structure(): void
    {
        $ids = $this->buildStructure();
        self::assertSame(403, $this->callAs('manager', 'POST', '/api/org/units', '{"code":"X","name":"X","kind":"unit","parent_id":'.$ids['ACME'].'}'));
        self::assertSame(403, $this->callAs('manager', 'PATCH', '/api/org/units/'.$ids['SALES'], '{"name":"Mine"}'));
        self::assertSame(403, $this->callAs('finance', 'POST', '/api/org/positions', '{"unit_id":'.$ids['SALES'].',"title":"X"}'));
        self::assertSame(403, $this->callAs('user', 'POST', '/api/org/employments', '{}'));
        self::assertSame(403, $this->callAs('user', 'DELETE', '/api/org/employments/'.$ids['emp_user']));
        self::assertSame(403, $this->callAs('user', 'POST', '/api/org/units/'.$ids['EAST'].'/archive'));
        self::assertSame(401, $this->callAs(null, 'GET', '/api/org/units'));
    }

    public function test_structure_invariants_are_enforced(): void
    {
        $ids = $this->buildStructure();

        // Units: required fields, unique code, one organization at the top, no cycles.
        self::assertSame(400, $this->callAs('admin', 'POST', '/api/org/units', '{"code":"X"}'));
        self::assertSame(409, $this->callAs('admin', 'POST', '/api/org/units', '{"code":"sales","name":"Dup","kind":"unit","parent_id":'.$ids['ACME'].'}'));
        self::assertSame(409, $this->callAs('admin', 'POST', '/api/org/units', '{"code":"ROOT2","name":"Second","kind":"unit"}'));
        self::assertSame(404, $this->callAs('admin', 'POST', '/api/org/units', '{"code":"X","name":"X","kind":"unit","parent_id":99999}'));
        self::assertSame(400, $this->callAs('admin', 'POST', '/api/org/units', '{"code":"bad code","name":"X","kind":"unit","parent_id":'.$ids['ACME'].'}'));
        self::assertSame(409, $this->callAs('admin', 'PATCH', '/api/org/units/'.$ids['SALES'], '{"parent_id":'.$ids['EAST'].'}'));
        self::assertSame(409, $this->callAs('admin', 'PATCH', '/api/org/units/'.$ids['EAST'], '{"code":"SALES"}'));
        self::assertSame(404, $this->callAs('admin', 'PATCH', '/api/org/units/99999', '{"name":"X"}'));
        self::assertSame(200, $this->callAs('admin', 'PATCH', '/api/org/units/'.$ids['EAST'], '{"name":"East region","parent_id":'.$ids['ACME'].'}'), $this->lastBody);
        self::assertSame(2, $this->json()['depth']);

        // Positions: one head per unit, no moving between units.
        self::assertSame(409, $this->callAs('admin', 'POST', '/api/org/positions', '{"unit_id":'.$ids['SALES'].',"title":"Co-head","is_head":true}'));
        self::assertSame(409, $this->callAs('admin', 'PATCH', '/api/org/positions/'.$ids['pos_rep'], '{"is_head":true}'));
        self::assertSame(400, $this->callAs('admin', 'PATCH', '/api/org/positions/'.$ids['pos_rep'], '{"unit_id":'.$ids['EAST'].'}'));
        self::assertSame(200, $this->callAs('admin', 'PATCH', '/api/org/positions/'.$ids['pos_rep'], '{"title":"Senior sales rep"}'));
        self::assertSame(404, $this->callAs('admin', 'POST', '/api/org/positions', '{"unit_id":99999,"title":"X"}'));
        self::assertSame(400, $this->callAs('admin', 'POST', '/api/org/positions', '{"title":"X"}'));

        // Employments: one primary post at a time, one holder per head post, no reporting cycles.
        $userId = $this->users['user']->getId();
        self::assertSame(409, $this->callAs('admin', 'POST', '/api/org/employments', json_encode(['user_id' => $userId, 'position_id' => $ids['pos_east'], 'starts_on' => '2026-03-01'])));
        self::assertSame(201, $this->callAs('admin', 'POST', '/api/org/employments', json_encode(['user_id' => $userId, 'position_id' => $ids['pos_east'], 'starts_on' => '2026-03-01', 'is_primary' => false])), $this->lastBody);
        self::assertSame(409, $this->callAs('admin', 'POST', '/api/org/employments', json_encode(['user_id' => $this->users['inventory']->getId(), 'position_id' => $ids['pos_head'], 'starts_on' => '2026-03-01'])));
        self::assertSame(409, $this->callAs('admin', 'PATCH', '/api/org/employments/'.$ids['emp_admin'], json_encode(['reports_to_id' => $userId])), 'The director reporting to their own subordinate would loop.');
        self::assertSame(400, $this->callAs('admin', 'PATCH', '/api/org/employments/'.$ids['emp_user'], '{"ends_on":"2025-01-01"}'));
        self::assertSame(400, $this->callAs('admin', 'PATCH', '/api/org/employments/'.$ids['emp_user'], json_encode(['position_id' => $ids['pos_east']])));
        self::assertSame(404, $this->callAs('admin', 'PATCH', '/api/org/employments/'.$ids['emp_user'], '{"reports_to_id":99999}'));
        self::assertSame(404, $this->callAs('admin', 'POST', '/api/org/employments', json_encode(['user_id' => 99999, 'position_id' => $ids['pos_rep'], 'starts_on' => '2026-01-01'])));
        self::assertSame(400, $this->callAs('admin', 'POST', '/api/org/employments', json_encode(['user_id' => $userId])));
        self::assertSame(404, $this->callAs('admin', 'PATCH', '/api/org/employments/99999', '{}'));

        self::assertSame(200, $this->callAs('admin', 'PATCH', '/api/org/employments/'.$ids['emp_user'], json_encode(['reports_to_id' => $this->users['admin']->getId()])), $this->lastBody);
        self::assertSame('admin@example.test', $this->json()['reports_to_email']);
        self::assertSame(200, $this->callAs('user', 'GET', '/api/org/me'));
        self::assertSame(['admin@example.test'], array_column($this->json()['superiors'], 'email'));
        self::assertSame(200, $this->callAs('admin', 'PATCH', '/api/org/employments/'.$ids['emp_user'], '{"clear_reports_to":true}'));
        self::assertNull($this->json()['reports_to_id']);
    }

    public function test_archiving_and_deleting_protect_structure_that_is_in_use(): void
    {
        $ids = $this->buildStructure();

        self::assertSame(409, $this->callAs('admin', 'POST', '/api/org/units/'.$ids['SALES'].'/archive'), 'Has an active sub-unit.');
        self::assertSame(409, $this->callAs('admin', 'POST', '/api/org/units/'.$ids['EAST'].'/archive'), 'Has an active employee.');
        self::assertSame(409, $this->callAs('admin', 'DELETE', '/api/org/units/'.$ids['EAST']), 'Has positions.');
        self::assertSame(409, $this->callAs('admin', 'POST', '/api/org/positions/'.$ids['pos_east'].'/archive'));

        self::assertSame(200, $this->callAs('admin', 'PATCH', '/api/org/employments/'.$ids['emp_finance'], '{"ends_on":"2026-02-28"}'), $this->lastBody);
        self::assertFalse($this->json()['is_active']);
        self::assertSame(409, $this->callAs('admin', 'DELETE', '/api/org/positions/'.$ids['pos_east']), 'A position that was held stays for history.');
        self::assertSame(200, $this->callAs('admin', 'POST', '/api/org/positions/'.$ids['pos_east'].'/archive'));
        self::assertSame(409, $this->callAs('admin', 'POST', '/api/org/positions/'.$ids['pos_east'].'/archive'));
        self::assertSame(200, $this->callAs('admin', 'POST', '/api/org/units/'.$ids['EAST'].'/archive'), $this->lastBody);
        self::assertNotNull($this->json()['archived_at']);
        self::assertSame(409, $this->callAs('admin', 'POST', '/api/org/units/'.$ids['EAST'].'/archive'));
        self::assertSame(200, $this->callAs('admin', 'GET', '/api/org/employments?unit_id='.$ids['EAST']));
        self::assertSame([], $this->json());
        self::assertSame(200, $this->callAs('admin', 'GET', '/api/org/employments?unit_id='.$ids['EAST'].'&include_ended=1'));
        self::assertCount(1, $this->json());
        self::assertSame(200, $this->callAs('admin', 'POST', '/api/org/units/'.$ids['EAST'].'/restore'));
        self::assertSame(409, $this->callAs('admin', 'POST', '/api/org/units/'.$ids['EAST'].'/restore'));
        self::assertSame(404, $this->callAs('admin', 'POST', '/api/org/units/99999/archive'));

        // An unused unit and position can be deleted outright; an employment recorded by mistake too.
        self::assertSame(201, $this->callAs('admin', 'POST', '/api/org/units', '{"code":"TMP","name":"Temp","kind":"section","parent_id":'.$ids['SALES'].'}'));
        $tmp = $this->json()['id'];
        self::assertSame(201, $this->callAs('admin', 'POST', '/api/org/positions', '{"unit_id":'.$tmp.',"title":"Temp"}'));
        $tmpPosition = $this->json()['id'];
        self::assertSame(204, $this->callAs('admin', 'DELETE', '/api/org/positions/'.$tmpPosition));
        self::assertSame(204, $this->callAs('admin', 'DELETE', '/api/org/units/'.$tmp));
        self::assertSame(404, $this->callAs('admin', 'DELETE', '/api/org/units/'.$tmp));
        self::assertSame(404, $this->callAs('admin', 'DELETE', '/api/org/positions/'.$tmpPosition));
        self::assertSame(204, $this->callAs('admin', 'DELETE', '/api/org/employments/'.$ids['emp_finance']));
        self::assertSame(404, $this->callAs('admin', 'DELETE', '/api/org/employments/'.$ids['emp_finance']));
        self::assertSame(404, $this->callAs('admin', 'PATCH', '/api/org/positions/99999', '{}'));
        self::assertSame(404, $this->callAs('admin', 'POST', '/api/org/positions/99999/archive'));
    }

    public function test_users_name_their_own_deputies_and_only_principals_or_admins_revoke(): void
    {
        $this->buildStructure();
        $today = new \DateTimeImmutable('today');
        $body = fn (string $deputy, int $from, int $to, array $extra = []): string => json_encode([
            'deputy_id' => $this->users[$deputy]->getId(),
            'starts_on' => $today->modify("+$from days")->format('Y-m-d'),
            'ends_on' => $today->modify("+$to days")->format('Y-m-d'),
            'reason' => 'Leave',
        ] + $extra);

        self::assertSame(201, $this->callAs('manager', 'POST', '/api/delegations', $body('user', 0, 5)), $this->lastBody);
        $delegation = $this->json();
        self::assertTrue($delegation['is_active']);
        self::assertSame('manager@example.test', $delegation['principal_email']);

        self::assertSame(200, $this->callAs('user', 'GET', '/api/org/me'));
        self::assertSame(['manager@example.test'], array_column($this->json()['acting_for'], 'email'));
        self::assertSame(200, $this->callAs('manager', 'GET', '/api/org/me'));
        self::assertSame('user@example.test', $this->json()['deputy']['email']);

        self::assertSame(409, $this->callAs('manager', 'POST', '/api/delegations', $body('finance', 3, 8)), 'Overlaps the existing one.');
        self::assertSame(400, $this->callAs('manager', 'POST', '/api/delegations', $body('manager', 10, 12)));
        self::assertSame(400, $this->callAs('manager', 'POST', '/api/delegations', $body('user', -5, -1)));
        self::assertInvalid($this->callAs('manager', 'POST', '/api/delegations', '{"deputy_id":1,"starts_on":"soon","ends_on":"2026-01-01"}'));
        self::assertSame(400, $this->callAs('manager', 'POST', '/api/delegations', json_encode(['starts_on' => '2099-01-01', 'ends_on' => '2099-01-02'])));
        self::assertSame(404, $this->callAs('manager', 'POST', '/api/delegations', json_encode(['deputy_id' => 99999, 'starts_on' => '2099-01-01', 'ends_on' => '2099-01-02'])));
        self::assertSame(403, $this->callAs('user', 'POST', '/api/delegations', $body('finance', 20, 21, ['principal_id' => $this->users['manager']->getId()])));
        self::assertSame(201, $this->callAs('admin', 'POST', '/api/delegations', $body('finance', 20, 21, ['principal_id' => $this->users['manager']->getId()])), $this->lastBody);
        self::assertSame('admin@example.test', $this->json()['created_by']);
        self::assertSame(404, $this->callAs('admin', 'POST', '/api/delegations', $body('finance', 30, 31, ['principal_id' => 99999])));

        self::assertSame(200, $this->callAs('user', 'GET', '/api/delegations'));
        self::assertCount(1, $this->json(), 'The deputy sees what they received, not the later one for finance.');
        self::assertSame(403, $this->callAs('user', 'GET', '/api/delegations?all=1'));
        self::assertSame(200, $this->callAs('admin', 'GET', '/api/delegations?all=1'));
        self::assertCount(2, $this->json());

        self::assertSame(403, $this->callAs('user', 'POST', '/api/delegations/'.$delegation['id'].'/revoke'), 'The deputy cannot revoke.');
        self::assertSame(200, $this->callAs('manager', 'POST', '/api/delegations/'.$delegation['id'].'/revoke'));
        self::assertSame('manager@example.test', $this->json()['revoked_by']);
        self::assertSame(409, $this->callAs('manager', 'POST', '/api/delegations/'.$delegation['id'].'/revoke'));
        self::assertSame(404, $this->callAs('manager', 'POST', '/api/delegations/99999/revoke'));
        self::assertSame(200, $this->callAs('manager', 'GET', '/api/delegations'));
        self::assertCount(1, $this->json(), 'Revoked delegations are hidden unless include_past is set.');
        self::assertSame(200, $this->callAs('manager', 'GET', '/api/delegations?include_past=1'));
        self::assertCount(2, $this->json());
    }
}
