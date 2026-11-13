<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Document;
use App\Entity\DocumentDisposal;
use App\Entity\DocumentType;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class DocumentRecordsTest extends TestCase
{
    public function test_a_registered_document_takes_effect_on_its_date_and_can_be_superseded(): void
    {
        $document = $this->document();
        $document->schedule(new \DateTimeImmutable('2026-07-01'), new \DateTimeImmutable('2026-12-31'), null);
        $document->register('POL-1', 'clerk', new \DateTimeImmutable('2026-06-01 09:00'));
        self::assertSame(Document::REGISTERED, $document->getStatus());
        self::assertFalse($document->takeEffect(new \DateTimeImmutable('2026-06-30')));
        self::assertTrue($document->takeEffect(new \DateTimeImmutable('2026-07-01')));
        self::assertFalse($document->takeEffect(new \DateTimeImmutable('2026-07-02')), 'Only once.');
        self::assertSame(Document::IN_FORCE, $document->getStatus());

        self::assertFalse($document->hasExpired(new \DateTimeImmutable('2026-12-31')), 'The expiry date is the last valid day.');
        self::assertTrue($document->hasExpired(new \DateTimeImmutable('2027-01-01')));
        $document->supersede();
        self::assertSame(Document::SUPERSEDED, $document->getStatus());
        self::assertTrue($document->hasExpired(new \DateTimeImmutable('2027-01-01')), 'Superseded documents still expire into the archive.');
        $this->expectDomain(fn () => $document->supersede());
    }

    public function test_reviews_move_the_review_date_forward(): void
    {
        $document = $this->document();
        $this->expectDomain(fn () => $document->recordReview(null, new \DateTimeImmutable('2026-06-01')), 'registered');
        $document->register('POL-1', 'clerk', new \DateTimeImmutable('2026-06-01'));
        $this->expectInvalid(fn () => $document->recordReview(new \DateTimeImmutable('2026-06-01'), new \DateTimeImmutable('2026-06-01')));
        $document->recordReview(new \DateTimeImmutable('2027-06-01'), new \DateTimeImmutable('2026-06-01'));
        self::assertSame('2027-06-01', $document->getReviewOn()?->format('Y-m-d'));
        $document->recordReview(null, new \DateTimeImmutable('2026-06-01'));
        self::assertNull($document->getReviewOn());
    }

    public function test_retention_legal_hold_and_disposal(): void
    {
        $document = $this->document(retentionYears: 2);
        $document->describe('Sensitive summary', null, Document::GENERAL, ['hr']);
        self::assertNull($document->retainUntil(), 'Retention starts when the document is archived.');
        $document->register('POL-1', 'clerk', new \DateTimeImmutable('2026-01-10'));
        $document->archive(new \DateTimeImmutable('2026-03-15 16:00'));
        self::assertSame('2028-03-15', $document->retainUntil()?->format('Y-m-d'));
        self::assertFalse($document->isDueForDisposal(new \DateTimeImmutable('2028-03-14')));
        self::assertTrue($document->isDueForDisposal(new \DateTimeImmutable('2028-03-15')));

        $this->expectInvalid(fn () => $document->placeLegalHold(' ', 'clerk', new \DateTimeImmutable()));
        $document->placeLegalHold('Case 2028/17', 'clerk', new \DateTimeImmutable());
        $this->expectDomain(fn () => $document->placeLegalHold('Again', 'clerk', new \DateTimeImmutable()), 'already');
        self::assertFalse($document->isDueForDisposal(new \DateTimeImmutable('2030-01-01')), 'A hold blocks disposal.');
        $this->expectDomain(fn () => new DocumentDisposal($document, 'clerk', 'Schedule 4', new \DateTimeImmutable('2030-01-01')), 'legal hold');
        $this->expectDomain(fn () => $document->dispose(new \DateTimeImmutable('2030-01-01')), 'legal hold');
        $document->releaseLegalHold();
        $this->expectDomain(fn () => $document->releaseLegalHold());

        $document->dispose(new \DateTimeImmutable('2030-01-01'));
        self::assertSame(Document::DISPOSED, $document->getStatus());
        self::assertNull($document->getSummary());
        self::assertSame([], $document->getTags());
        self::assertSame('pol-1 policy one policy', $document->getSearchText(), 'Only the number, title and type stay searchable.');
        self::assertFalse($document->isDeletable(), 'Tombstones are never deleted.');
        $this->expectDomain(fn () => $document->placeLegalHold('Late', 'clerk', new \DateTimeImmutable()), 'disposed');
    }

    public function test_drafts_under_legal_hold_cannot_be_deleted_and_types_without_retention_are_kept(): void
    {
        $draft = $this->document();
        self::assertTrue($draft->isDeletable());
        $draft->placeLegalHold('Litigation hold', 'clerk', new \DateTimeImmutable());
        self::assertFalse($draft->isDeletable());

        $kept = $this->document(retentionYears: null);
        $kept->register('POL-2', 'clerk', new \DateTimeImmutable('2000-01-01'));
        $kept->archive(new \DateTimeImmutable('2000-02-01'));
        self::assertNull($kept->retainUntil());
        self::assertFalse($kept->isDueForDisposal(new \DateTimeImmutable('2090-01-01')));
    }

    public function test_disposal_needs_a_second_person(): void
    {
        $document = $this->document(retentionYears: 1);
        $document->register('POL-1', 'clerk', new \DateTimeImmutable('2020-01-01'));
        $document->archive(new \DateTimeImmutable('2020-02-01'));
        $this->expectInvalid(fn () => new DocumentDisposal($document, 'clerk@example.test', ' ', new \DateTimeImmutable('2026-01-01')));
        $disposal = new DocumentDisposal($document, 'clerk@example.test', 'Retention schedule 4.2', new \DateTimeImmutable('2026-01-01'));
        self::assertSame('2021-02-01', $disposal->getRetainUntil()->format('Y-m-d'));
        $this->expectDomain(fn () => $disposal->approve('CLERK@example.test', null, [], new \DateTimeImmutable()), 'someone other');
        $this->expectInvalid(fn () => $disposal->reject('admin@example.test', '', new \DateTimeImmutable()));
        $disposal->approve('admin@example.test', 'OK', ['versions' => []], new \DateTimeImmutable());
        self::assertSame(DocumentDisposal::APPROVED, $disposal->getStatus());
        $this->expectDomain(fn () => $disposal->reject('other@example.test', 'Too late', new \DateTimeImmutable()), 'already');
    }

    private function document(?int $retentionYears = 10): Document
    {
        $type = new DocumentType('POL', 'Policy');
        $type->configure(false, Document::GENERAL, $retentionYears, [], true);
        $document = new Document($type, (new User())->setEmail('author@example.test'), 'Policy one');
        $document->addVersion();

        return $document;
    }

    private function expectDomain(callable $action, string $contains = ''): void
    {
        try {
            $action();
            self::fail('Expected a DomainException.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString($contains, $exception->getMessage());
        }
    }

    private function expectInvalid(callable $action): void
    {
        try {
            $action();
            self::fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
    }
}
