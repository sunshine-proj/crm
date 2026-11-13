<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Document;
use App\Entity\DocumentLink;
use App\Entity\DocumentType;
use App\Entity\OrgUnit;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class DocumentRegistryEntitiesTest extends TestCase
{
    public function test_type_codes_patterns_and_number_formatting(): void
    {
        $type = new DocumentType(' order ', 'Order');
        self::assertSame('ORDER', $type->getCode());
        self::assertSame('ORDER-2026-0007', $type->formatNumber(7, new \DateTimeImmutable('2026-05-01'), null));

        $type->setNumberPattern('{UNIT}/{YY}/{SEQ:3}');
        self::assertSame('SALES/26/012', $type->formatNumber(12, new \DateTimeImmutable('2026-05-01'), new OrgUnit('SALES', 'Sales', OrgUnit::ORGANIZATION)));
        self::assertSame('GEN/26/1234', $type->formatNumber(1234, new \DateTimeImmutable('2026-05-01'), null), 'Padding is a minimum width.');

        foreach (['ORD-{SEQ}', 'ORD-{YYYY}', 'ORD {YYYY}-{SEQ}', '{FOO}-{YYYY}-{SEQ}'] as $bad) {
            $this->assertInvalid(fn () => $type->setNumberPattern($bad));
        }
        $this->assertInvalid(fn () => new DocumentType('1BAD', 'Bad'));
        $this->assertInvalid(fn () => new DocumentType('OK', ' '));
        $this->assertInvalid(fn () => $type->configure(true, 'secret', null, [], true));
        $this->assertInvalid(fn () => $type->configure(true, 'general', 0, [], true));
        $this->assertInvalid(fn () => $type->configure(true, 'general', null, ['colour'], true));

        $type->configure(false, Document::CONFIDENTIAL, 5, ['summary', 'summary', 'client'], false);
        self::assertSame(['summary', 'client'], $type->getRequiredFields());
        self::assertFalse($type->isActive());
        $this->expectException(\DomainException::class);
        new Document($type, $this->user('a'), 'Inactive type');
    }

    public function test_new_documents_take_type_defaults_and_validate_metadata(): void
    {
        $type = new DocumentType('MEMO', 'Memo');
        $type->configure(false, Document::CONFIDENTIAL, null, [], true);
        $document = new Document($type, $this->user('a'), '  Budget memo ');

        self::assertSame('Budget memo', $document->getTitle());
        self::assertSame(Document::DRAFT, $document->getStatus());
        self::assertTrue($document->isConfidential());

        $document->describe('  ', null, Document::GENERAL, [' Finance ', 'finance', '', 'Q3']);
        self::assertNull($document->getSummary());
        self::assertSame(['finance', 'q3'], $document->getTags());

        $this->assertInvalid(fn () => $document->describe(null, null, 'secret', []));
        $this->assertInvalid(fn () => $document->describe(null, null, Document::GENERAL, [str_repeat('x', 41)]));
        $this->assertInvalid(fn () => $document->describe(null, null, Document::GENERAL, array_map('strval', range(1, 21))));
        $this->assertInvalid(fn () => $document->schedule(new \DateTimeImmutable('2026-05-02'), new \DateTimeImmutable('2026-05-01'), null));
        $this->assertInvalid(fn () => $document->retitle(''));

        $archived = new OrgUnit('OLD', 'Old', OrgUnit::ORGANIZATION);
        $archived->archive();
        $this->expectException(\DomainException::class);
        $document->describe(null, $archived, Document::GENERAL, []);
    }

    public function test_registration_requires_a_file_required_fields_no_lock_and_approval_where_configured(): void
    {
        $type = new DocumentType('POL', 'Policy');
        $type->configure(false, Document::GENERAL, null, ['owner_unit', 'effective_on'], true);
        $author = $this->user('a');
        $document = new Document($type, $author, 'Travel policy');
        $now = new \DateTimeImmutable('2026-05-10 09:00');

        $this->assertConflict(fn () => $document->register('POL-1', 'clerk', $now), 'Upload the document file');
        $document->addVersion();
        $this->assertConflict(fn () => $document->register('POL-1', 'clerk', $now), 'owner_unit, effective_on');
        self::assertSame(['owner_unit', 'effective_on'], $document->missingRequiredFields());

        $document->describe(null, new OrgUnit('ACME', 'Acme', OrgUnit::ORGANIZATION), Document::GENERAL, []);
        $document->schedule(new \DateTimeImmutable('2026-06-01'), null, null);
        $document->checkOut($author);
        self::assertTrue($document->isLockedFor($this->user('b')));
        self::assertFalse($document->isLockedFor($author));
        $this->assertConflict(fn () => $document->register('POL-1', 'clerk', $now), 'checked out');
        $document->checkIn();

        $document->register('POL-1', 'clerk@example.test', $now);
        self::assertSame(Document::REGISTERED, $document->getStatus(), 'Effective date ahead: registered, not yet in force.');
        self::assertSame('POL-1', $document->getRegistrationNumber());
        self::assertTrue($document->isRegistered());
        self::assertFalse($document->isEditable());
        self::assertFalse($document->isDeletable());
        $this->assertConflict(fn () => $document->retitle('Changed'), 'can no longer be edited');
        $this->assertConflict(fn () => $document->addVersion(), 'can no longer be edited');
        $this->assertConflict(fn () => $document->register('POL-2', 'clerk', $now), 'Only a draft or approved');

        $document->archive();
        self::assertSame(Document::ARCHIVED, $document->getStatus());
        self::assertNotNull($document->getArchivedAt());
        $this->assertConflict(fn () => $document->archive(), 'Only registered');
        $this->assertConflict(fn () => $document->replaceReaders([]), 'archived');
    }

    public function test_types_needing_approval_cannot_be_registered_from_draft_and_a_new_file_voids_approval(): void
    {
        $document = new Document(new DocumentType('ORDER', 'Order'), $this->user('a'), 'Order 1');
        $document->addVersion();
        self::assertFalse($document->canBeRegistered());
        $this->assertConflict(fn () => $document->register('ORD-1', 'clerk', new \DateTimeImmutable()), 'must be approved');

        // Phase 3 sets the approved state; simulate it to pin the rule that new content needs a new approval.
        (new \ReflectionProperty($document, 'status'))->setValue($document, Document::APPROVED);
        self::assertTrue($document->canBeRegistered());
        self::assertSame(2, $document->addVersion());
        self::assertSame(Document::DRAFT, $document->getStatus());

        (new \ReflectionProperty($document, 'status'))->setValue($document, Document::APPROVED);
        $document->register('ORD-1', 'clerk', new \DateTimeImmutable());
        self::assertSame(Document::IN_FORCE, $document->getStatus(), 'No effective date: in force at registration.');
    }

    public function test_readers_exclude_the_author_and_duplicates_and_search_text_is_normalized(): void
    {
        $author = $this->user('a', 1);
        $reader = $this->user('b', 2);
        $document = new Document(new DocumentType('MEMO', 'Memo'), $author, 'Quarterly REVIEW');
        $document->replaceReaders([$author, $reader, $reader]);
        self::assertSame([$reader], $document->getReaders());
        self::assertTrue($document->hasReader($reader));
        self::assertFalse($document->hasReader($author));

        $document->describe("Line one\n\nline two", null, Document::GENERAL, ['Plan']);
        $document->refreshSearchText('Extracted   BODY');
        self::assertSame('quarterly review line one line two [tag:plan] memo extracted body', $document->getSearchText());
        self::assertTrue($document->isDeletable());
    }

    public function test_links_validate_kind_and_target(): void
    {
        $type = new DocumentType('MEMO', 'Memo');
        $a = new Document($type, $this->user('a'), 'A');
        $b = new Document($type, $this->user('a'), 'B');
        self::assertSame('amends', (new DocumentLink($a, $b, 'amends', 'x'))->getKind());
        $this->assertInvalid(fn () => new DocumentLink($a, $b, 'copies', 'x'));
        $this->assertInvalid(fn () => new DocumentLink($a, $a, 'amends', 'x'));
    }

    private function user(string $name, ?int $id = null): User
    {
        $user = (new User())->setEmail($name.'@example.test');
        (new \ReflectionProperty($user, 'id'))->setValue($user, $id ?? crc32($name));

        return $user;
    }

    private function assertInvalid(callable $action): void
    {
        try {
            $action();
            self::fail('Invalid input accepted.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
    }

    private function assertConflict(callable $action, string $message): void
    {
        try {
            $action();
            self::fail('Expected a conflict: '.$message);
        } catch (\DomainException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
        }
    }
}
