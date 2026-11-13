<?php

namespace App\Tests\Unit\Service\Org;

use App\Entity\Delegation;
use App\Entity\Employment;
use App\Entity\OrgUnit;
use App\Entity\Position;
use App\Entity\User;
use App\Service\Org\OrgChart;
use PHPUnit\Framework\TestCase;

/**
 * Structure used by most tests:
 *   ACME (ceo)
 *   ├── SALES (head: sales_head) — rep
 *   │   └── EAST (vacant head post) — east_rep
 *   │       └── EAST1 (head: east1_head) — east1_rep
 *   └── IT (no head post) — dev
 */
final class OrgChartTest extends TestCase
{
    private int $nextId = 1;
    /** @var array<string, User> */
    private array $u = [];
    /** @var array<string, OrgUnit> */
    private array $units = [];
    /** @var array<string, Position> */
    private array $heads = [];
    /** @var list<Employment> */
    private array $employments = [];

    protected function setUp(): void
    {
        $this->units['ACME'] = $this->withId(new OrgUnit('ACME', 'Acme', OrgUnit::ORGANIZATION));
        $this->units['SALES'] = $this->withId(new OrgUnit('SALES', 'Sales', OrgUnit::UNIT, $this->units['ACME']));
        $this->units['EAST'] = $this->withId(new OrgUnit('EAST', 'East', OrgUnit::SECTION, $this->units['SALES']));
        $this->units['EAST1'] = $this->withId(new OrgUnit('EAST1', 'East 1', OrgUnit::SECTION, $this->units['EAST']));
        $this->units['IT'] = $this->withId(new OrgUnit('IT', 'IT', OrgUnit::UNIT, $this->units['ACME']));
        foreach (['ACME', 'SALES', 'EAST', 'EAST1'] as $code) {
            $this->heads[$code] = $this->withId(new Position($this->units[$code], 'Head of '.$code, true));
        }
        $this->employ('ceo', $this->heads['ACME']);
        $this->employ('sales_head', $this->heads['SALES']);
        $this->employ('rep', $this->post('SALES'));
        $this->employ('east_rep', $this->post('EAST'));
        $this->employ('east1_head', $this->heads['EAST1']);
        $this->employ('east1_rep', $this->post('EAST1'));
        $this->employ('dev', $this->post('IT'));
    }

    public function test_superiors_follow_unit_heads_and_skip_vacant_posts(): void
    {
        $chart = $this->chart();

        self::assertSame('sales_head', $this->nameOf($chart->directSuperior($this->u['rep'])));
        self::assertSame('sales_head', $this->nameOf($chart->directSuperior($this->u['east_rep'])), 'Vacant EAST head: go up to SALES.');
        self::assertSame('east1_head', $this->nameOf($chart->directSuperior($this->u['east1_rep'])));
        self::assertSame(['sales_head', 'ceo'], $this->names($chart->superiors($this->u['east1_head'])), 'A head reports to the head of the parent unit (skipping the vacant one).');
        self::assertSame('ceo', $this->nameOf($chart->directSuperior($this->u['dev'])), 'A unit without a head post reports up.');
        self::assertNull($chart->directSuperior($this->u['ceo']));
        self::assertSame([], $chart->superiors($this->u['ceo']));
        self::assertTrue($chart->isSuperiorOf($this->u['ceo'], $this->u['east1_rep']));
        self::assertFalse($chart->isSuperiorOf($this->u['east1_rep'], $this->u['ceo']));
        self::assertFalse($chart->isSuperiorOf($this->u['dev'], $this->u['rep']), 'Peers in other units are not superiors.');
    }

    public function test_users_without_employment_have_no_superiors(): void
    {
        $outsider = $this->withId((new User())->setEmail('outsider@example.test'));
        self::assertNull($this->chart()->directSuperior($outsider));
        self::assertNull($this->chart()->primaryEmployment($outsider));
    }

    public function test_an_explicit_reporting_override_wins_and_cycles_are_cut(): void
    {
        $this->employmentOf('dev')->reportTo($this->u['sales_head']);
        $chart = $this->chart();
        self::assertSame(['sales_head', 'ceo'], $this->names($chart->superiors($this->u['dev'])));

        // A loop recorded in the data (ceo reports to dev) must not hang the chain.
        $this->employmentOf('ceo')->reportTo($this->u['dev']);
        $chain = $this->names($this->chart()->superiors($this->u['dev']));
        self::assertSame(['sales_head', 'ceo'], $chain);
    }

    public function test_subordinates_direct_and_transitive(): void
    {
        $chart = $this->chart();
        self::assertSame(['east1_head', 'east_rep', 'rep'], $this->names($chart->subordinates($this->u['sales_head'], true)));
        self::assertSame(['east1_head', 'east1_rep', 'east_rep', 'rep'], $this->names($chart->subordinates($this->u['sales_head'])));
        self::assertCount(6, $chart->subordinates($this->u['ceo']));
        self::assertSame([], $chart->subordinates($this->u['rep']));
    }

    public function test_managed_units_members_and_subtrees_exclude_archived_branches(): void
    {
        $chart = $this->chart();
        $ids = fn (array $codes): array => array_map(fn (string $code): int => (int) $this->units[$code]->getId(), $codes);

        self::assertSame($ids(['SALES', 'EAST', 'EAST1']), $chart->managedUnitIds($this->u['sales_head']));
        self::assertSame([], $chart->managedUnitIds($this->u['rep']));
        self::assertSame(['rep', 'sales_head'], $this->names($chart->members((int) $this->units['SALES']->getId())));
        self::assertSame(['east1_head', 'east1_rep', 'east_rep', 'rep', 'sales_head'], $this->names($chart->members((int) $this->units['SALES']->getId(), true)));
        self::assertSame(2, $chart->memberCount((int) $this->units['SALES']->getId()));
        self::assertSame('sales_head', $this->nameOf($chart->headOf((int) $this->units['SALES']->getId())));
        self::assertNull($chart->headOf((int) $this->units['EAST']->getId()));

        $this->units['EAST1']->archive();
        self::assertSame($ids(['SALES', 'EAST']), $this->chart()->subtreeIds((int) $this->units['SALES']->getId()));
        self::assertSame([], $this->chart()->subtreeIds(999));
        self::assertNull($this->chart()->unit(999));
    }

    public function test_ended_future_and_archived_employments_do_not_count(): void
    {
        $this->employmentOf('rep')->endOn(new \DateTimeImmutable('2026-05-31'));
        $this->employ('newcomer', $this->post('SALES'), '2026-07-01');
        $chart = $this->chart(new \DateTimeImmutable('2026-06-15'));

        self::assertSame(['sales_head'], $this->names($chart->members((int) $this->units['SALES']->getId())));
        self::assertNull($chart->primaryEmployment($this->u['newcomer']));
        self::assertSame('2026-06-15', $chart->day()->format('Y-m-d'));
    }

    public function test_the_primary_employment_decides_the_superior_and_an_acting_head_leads_a_vacant_unit(): void
    {
        // east_rep is also acting head of the vacant EAST section.
        $acting = $this->employ('east_rep', $this->heads['EAST'], '2026-01-01', false, true);
        $chart = $this->chart();

        self::assertSame('east_rep', $this->nameOf($chart->headOf((int) $this->units['EAST']->getId())));
        self::assertSame('east_rep', $this->nameOf($chart->directSuperior($this->u['east1_head'])));
        self::assertSame('sales_head', $this->nameOf($chart->directSuperior($this->u['east_rep'])), 'Their own primary post sits in EAST; they skip themselves.');
        self::assertSame($this->employmentOf('east_rep'), $chart->primaryEmployment($this->u['east_rep']));
        self::assertCount(2, $chart->employmentsOf($this->u['east_rep']));
        self::assertContains((int) $this->units['EAST']->getId(), $chart->managedUnitIds($this->u['east_rep']));
        self::assertNotSame($acting, $chart->primaryEmployment($this->u['east_rep']));
    }

    public function test_deputies_follow_chains_and_stop_at_cycles(): void
    {
        $day = new \DateTimeImmutable('2026-06-15');
        $delegations = [
            new Delegation($this->u['sales_head'], $this->u['rep'], $day, $day, null, 'x'),
            new Delegation($this->u['rep'], $this->u['east_rep'], $day, $day, null, 'x'),
            new Delegation($this->u['east_rep'], $this->u['sales_head'], $day, $day, null, 'x'),
            new Delegation($this->u['ceo'], $this->u['dev'], $day->modify('+1 day'), $day->modify('+2 days'), null, 'x'),
        ];
        $chart = new OrgChart($day, $this->units, $this->employments, $delegations);

        self::assertSame('east_rep', $this->nameOf($chart->deputyFor($this->u['sales_head'])), 'rep is away too, so their deputy acts.');
        self::assertNull($chart->deputyFor($this->u['ceo']), 'Delegation not yet in force.');
        self::assertSame(['sales_head'], $this->names($chart->principalsOf($this->u['rep'])));
    }

    private function chart(?\DateTimeImmutable $day = null): OrgChart
    {
        return new OrgChart($day ?? new \DateTimeImmutable('2026-06-15'), $this->units, $this->employments);
    }

    private function post(string $unit): Position
    {
        return $this->withId(new Position($this->units[$unit], 'Specialist'));
    }

    private function employ(string $name, Position $position, string $from = '2026-01-01', bool $primary = true, bool $acting = false): Employment
    {
        $this->u[$name] ??= $this->withId((new User())->setEmail($name.'@example.test'));
        $employment = $this->withId(new Employment($this->u[$name], $position, new \DateTimeImmutable($from), $primary, $acting));
        $this->employments[] = $employment;

        return $employment;
    }

    private function employmentOf(string $name): Employment
    {
        foreach ($this->employments as $employment) {
            if ($employment->getUser() === $this->u[$name] && $employment->isPrimary()) {
                return $employment;
            }
        }
        throw new \LogicException($name);
    }

    /**
     * @template T of object
     * @param T $entity
     * @return T
     */
    private function withId(object $entity): object
    {
        (new \ReflectionProperty($entity, 'id'))->setValue($entity, $this->nextId++);

        return $entity;
    }

    private function nameOf(?User $user): ?string
    {
        return $user === null ? null : strstr($user->getUserIdentifier(), '@', true);
    }

    /** @param list<User> $users */
    private function names(array $users): array
    {
        return array_map(fn (User $user): string => (string) $this->nameOf($user), $users);
    }
}
