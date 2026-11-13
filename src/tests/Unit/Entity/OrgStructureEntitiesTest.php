<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Delegation;
use App\Entity\Employment;
use App\Entity\OrgUnit;
use App\Entity\Position;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class OrgStructureEntitiesTest extends TestCase
{
    public function test_a_unit_normalizes_its_code_and_validates_name_and_kind(): void
    {
        $root = new OrgUnit(' acme ', ' Acme Ltd ', OrgUnit::ORGANIZATION);
        self::assertSame('ACME', $root->getCode());
        self::assertSame('Acme Ltd', $root->getName());
        self::assertSame(1, $root->depth());

        foreach ([['bad code', 'Name', OrgUnit::ORGANIZATION], ['OK', '  ', OrgUnit::ORGANIZATION], ['OK', 'Name', 'team']] as [$code, $name, $kind]) {
            try {
                new OrgUnit($code, $name, $kind);
                self::fail('Invalid unit accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_only_the_top_level_unit_is_an_organization(): void
    {
        $root = new OrgUnit('ACME', 'Acme', OrgUnit::ORGANIZATION);
        $this->expectDomain(fn () => new OrgUnit('SALES', 'Sales', OrgUnit::UNIT));
        $this->expectDomain(fn () => new OrgUnit('SUB', 'Sub', OrgUnit::ORGANIZATION, $root));
    }

    public function test_moves_refuse_cycles_archived_parents_and_excess_depth(): void
    {
        $root = new OrgUnit('ACME', 'Acme', OrgUnit::ORGANIZATION);
        $sales = new OrgUnit('SALES', 'Sales', OrgUnit::UNIT, $root);
        $east = new OrgUnit('EAST', 'East', OrgUnit::SECTION, $sales);
        self::assertTrue($east->isDescendantOf($root));
        self::assertSame(3, $east->depth());

        $this->expectDomain(fn () => $sales->define('SALES', 'Sales', OrgUnit::UNIT, $east));
        $this->expectDomain(fn () => $sales->define('SALES', 'Sales', OrgUnit::UNIT, $sales));

        $archived = new OrgUnit('OLD', 'Old', OrgUnit::UNIT, $root);
        $archived->archive();
        $this->expectDomain(fn () => new OrgUnit('NEW', 'New', OrgUnit::SECTION, $archived));

        $deepest = $root;
        for ($level = 2; $level <= OrgUnit::MAX_DEPTH; ++$level) {
            $deepest = new OrgUnit('L'.$level, 'Level '.$level, OrgUnit::SECTION, $deepest);
        }
        self::assertSame(OrgUnit::MAX_DEPTH, $deepest->depth());
        $this->expectDomain(fn () => new OrgUnit('TOO', 'Too deep', OrgUnit::SECTION, $deepest));
    }

    public function test_archive_and_restore_follow_the_parent(): void
    {
        $root = new OrgUnit('ACME', 'Acme', OrgUnit::ORGANIZATION);
        $sales = new OrgUnit('SALES', 'Sales', OrgUnit::UNIT, $root);
        $this->expectDomain(fn () => $sales->restore());
        $sales->archive();
        self::assertTrue($sales->isArchived());
        $this->expectDomain(fn () => $sales->archive());
        $root->archive();
        $this->expectDomain(fn () => $sales->restore());
        $root->restore();
        $sales->restore();
        self::assertFalse($sales->isArchived());
        $this->expectDomain(fn () => new Position($this->archivedUnit(), 'Clerk'));
    }

    public function test_position_validation_and_archiving(): void
    {
        $unit = new OrgUnit('ACME', 'Acme', OrgUnit::ORGANIZATION);
        $position = new Position($unit, ' Director ', true);
        self::assertSame('Director', $position->getTitle());
        self::assertTrue($position->isHead());
        $this->expectException(\InvalidArgumentException::class);
        $position->describe('', false);
    }

    public function test_employment_activity_overlap_and_reporting_override(): void
    {
        $user = (new User())->setEmail('a@example.test');
        $boss = (new User())->setEmail('b@example.test');
        $position = new Position(new OrgUnit('ACME', 'Acme', OrgUnit::ORGANIZATION), 'Clerk');
        $employment = new Employment($user, $position, new \DateTimeImmutable('2026-01-10 15:00'));
        $employment->endOn(new \DateTimeImmutable('2026-06-30'));

        self::assertSame('2026-01-10 00:00', $employment->getStartsOn()->format('Y-m-d H:i'));
        self::assertFalse($employment->isActiveOn(new \DateTimeImmutable('2026-01-09')));
        self::assertTrue($employment->isActiveOn(new \DateTimeImmutable('2026-01-10 23:00')));
        self::assertTrue($employment->isActiveOn(new \DateTimeImmutable('2026-06-30')));
        self::assertFalse($employment->isActiveOn(new \DateTimeImmutable('2026-07-01')));
        self::assertTrue($employment->overlaps(new \DateTimeImmutable('2026-06-30'), null));
        self::assertFalse($employment->overlaps(new \DateTimeImmutable('2026-07-01'), null));
        self::assertFalse($employment->overlaps(new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2026-01-09')));

        $employment->reportTo($boss);
        self::assertSame($boss, $employment->getReportsTo());
        $employment->classify(false, true);
        self::assertFalse($employment->isPrimary());
        self::assertTrue($employment->isActing());

        try {
            $employment->reportTo($user);
            self::fail('Self-reporting accepted.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        $this->expectException(\InvalidArgumentException::class);
        $employment->endOn(new \DateTimeImmutable('2026-01-01'));
    }

    public function test_employment_cannot_start_on_an_archived_position(): void
    {
        $position = new Position(new OrgUnit('ACME', 'Acme', OrgUnit::ORGANIZATION), 'Clerk');
        $position->archive();
        $this->expectDomain(fn () => $position->archive());
        $this->expectException(\DomainException::class);
        new Employment((new User())->setEmail('a@example.test'), $position, new \DateTimeImmutable('2026-01-01'));
    }

    public function test_delegation_dates_overlap_and_revocation(): void
    {
        $principal = (new User())->setEmail('p@example.test');
        $deputy = (new User())->setEmail('d@example.test');
        $delegation = new Delegation($principal, $deputy, new \DateTimeImmutable('2026-03-01'), new \DateTimeImmutable('2026-03-10'), '  ', 'p@example.test');
        self::assertNull($delegation->getReason());
        self::assertTrue($delegation->isActiveOn(new \DateTimeImmutable('2026-03-10')));
        self::assertFalse($delegation->isActiveOn(new \DateTimeImmutable('2026-03-11')));
        self::assertTrue($delegation->overlaps(new \DateTimeImmutable('2026-02-01'), new \DateTimeImmutable('2026-03-01')));
        self::assertFalse($delegation->overlaps(new \DateTimeImmutable('2026-03-11'), new \DateTimeImmutable('2026-03-12')));

        $delegation->revoke('admin@example.test');
        self::assertSame('admin@example.test', $delegation->getRevokedBy());
        self::assertFalse($delegation->isActiveOn(new \DateTimeImmutable('2026-03-05')));
        self::assertFalse($delegation->overlaps(new \DateTimeImmutable('2026-03-01'), new \DateTimeImmutable('2026-03-10')));
        $this->expectDomain(fn () => $delegation->revoke('x'));

        foreach ([[$principal, $principal, '2026-03-01', '2026-03-02'], [$principal, $deputy, '2026-03-02', '2026-03-01']] as [$a, $b, $from, $to]) {
            try {
                new Delegation($a, $b, new \DateTimeImmutable($from), new \DateTimeImmutable($to), null, 'x');
                self::fail('Invalid delegation accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    private function archivedUnit(): OrgUnit
    {
        $unit = new OrgUnit('GONE', 'Gone', OrgUnit::ORGANIZATION);
        $unit->archive();

        return $unit;
    }

    private function expectDomain(callable $action): void
    {
        try {
            $action();
            self::fail('Expected a domain rule to refuse the change.');
        } catch (\DomainException) {
            self::addToAssertionCount(1);
        }
    }
}
