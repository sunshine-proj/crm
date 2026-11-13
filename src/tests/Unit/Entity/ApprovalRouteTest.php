<?php

namespace App\Tests\Unit\Entity;

use App\Entity\ApprovalRoute;
use App\Entity\ApprovalStage;
use App\Entity\ApprovalStep;
use App\Entity\Document;
use App\Entity\DocumentType;
use App\Entity\DocumentVersion;
use App\Entity\StoredFile;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class ApprovalRouteTest extends TestCase
{
    private int $nextId = 1;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-06-01 10:00');
    }

    public function test_sequential_stages_with_all_and_any_rules(): void
    {
        [$route, $a, $b, $c, $d] = $this->route([[ApprovalStage::ALL, ['a', 'b']], [ApprovalStage::ANY, ['c', 'd']]]);
        $first = $route->start($this->now);
        self::assertSame(1, $first->getPosition());
        self::assertSame(ApprovalStage::WAITING, $route->getStages()[1]->getStatus());
        self::assertSame(ApprovalStep::WAITING, $c->getStatus(), 'Later stages wait.');

        self::assertSame('pending', $route->decide($a, ApprovalStep::APPROVED, 'a', null, null, $this->now), '"all" needs b too.');
        self::assertSame('next_stage', $route->decide($b, ApprovalStep::APPROVED, 'b', null, 'Fine', $this->now));
        self::assertSame(ApprovalStage::COMPLETED, $first->getStatus());
        self::assertTrue($c->isOpen());

        self::assertSame(ApprovalRoute::APPROVED, $route->decide($d, ApprovalStep::APPROVED, 'd', null, null, $this->now), '"any": one approval completes it.');
        self::assertSame(ApprovalStep::SKIPPED, $c->getStatus());
        self::assertFalse($route->isActive());
        self::assertNotNull($route->getCompletedAt());
        $this->expectDomain(fn () => $route->decide($c, ApprovalStep::APPROVED, 'c', null, null, $this->now));
    }

    public function test_reject_and_return_end_the_route_and_need_a_reason(): void
    {
        [$route, $a, $b, $c] = $this->route([[ApprovalStage::ALL, ['a', 'b']], [ApprovalStage::ALL, ['c']]]);
        $route->start($this->now);
        try {
            $route->decide($a, ApprovalStep::REJECTED, 'a', null, '  ', $this->now);
            self::fail('A rejection without a reason was accepted.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        self::assertSame(ApprovalStep::REJECTED, $route->decide($a, ApprovalStep::REJECTED, 'a', null, 'Wrong figures', $this->now));
        self::assertSame(ApprovalRoute::REJECTED, $route->getStatus());
        self::assertSame(ApprovalStep::CANCELLED, $b->getStatus());
        self::assertSame(ApprovalStep::CANCELLED, $c->getStatus());
        self::assertSame(ApprovalStage::CANCELLED, $route->getStages()[1]->getStatus());

        [$second, $x] = $this->route([[ApprovalStage::ALL, ['x']]]);
        $second->start($this->now);
        self::assertSame(ApprovalStep::RETURNED, $second->decide($x, ApprovalStep::RETURNED, 'x', 'principal@example.test', 'Add the annex', $this->now));
        self::assertSame(ApprovalRoute::RETURNED, $second->getStatus());
        self::assertSame('principal@example.test', $x->getOnBehalfOf());
    }

    public function test_consultations_are_advisory_and_close_with_their_parent(): void
    {
        [$route, $a, $b] = $this->route([[ApprovalStage::ALL, ['a', 'b']]]);
        $route->start($this->now);
        $consultee = $this->user('sub');
        $consultation = $route->consult($a, $consultee, 'Analyst', 'Sales', null, 'a@example.test');
        self::assertTrue($consultation->isOpen());
        self::assertSame(2, $a->getStage()->getStepCount(), 'Consultations are not counted.');
        $this->expectDomain(fn () => $route->consult($a, $consultee, null, null, null, 'a'));
        $this->expectDomain(fn () => $route->consult($consultation, $this->user('other'), null, null, null, 'x'));
        $this->expectDomain(fn () => $route->decide($consultation, ApprovalStep::APPROVED, 'sub', null, null, $this->now));
        $this->expectDomain(fn () => $route->review($a, 'a', null, 'text', $this->now));

        $route->review($consultation, 'sub', null, 'Looks right', $this->now);
        self::assertSame(ApprovalStep::REVIEWED, $consultation->getStatus());
        $second = $route->consult($b, $consultee, null, null, null, 'b');
        self::assertSame('pending', $route->decide($a, ApprovalStep::APPROVED, 'a', null, null, $this->now));
        self::assertSame(ApprovalRoute::APPROVED, $route->decide($b, ApprovalStep::APPROVED, 'b', null, null, $this->now));
        self::assertSame(ApprovalStep::CANCELLED, $second->getStatus(), 'Its parent decided without waiting.');
    }

    public function test_reviewers_can_be_added_to_the_running_stage_and_withdrawal_cancels_everything(): void
    {
        [$route, $a] = $this->route([[ApprovalStage::ALL, ['a']]]);
        $this->expectDomain(fn () => $route->addReviewer($this->user('r'), null, null, null, 'author'));
        $route->start($this->now);
        $reviewer = $route->addReviewer($this->user('r'), 'Lawyer', 'Legal', null, 'author');
        self::assertTrue($reviewer->isOpen());
        self::assertSame('pending', $route->decide($a, ApprovalStep::APPROVED, 'a', null, null, $this->now), 'The added reviewer must agree too.');
        try {
            $route->addReviewer($a->getAssignee(), null, null, null, 'author');
            self::fail('A duplicate participant was accepted.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        $route->withdraw($this->now);
        self::assertSame(ApprovalRoute::WITHDRAWN, $route->getStatus());
        self::assertSame(ApprovalStep::CANCELLED, $reviewer->getStatus());
        $this->expectDomain(fn () => $route->withdraw($this->now));
    }

    public function test_completed_stages_can_be_carried_into_the_next_round(): void
    {
        [$old, $a, $b] = $this->route([[ApprovalStage::ALL, ['a']], [ApprovalStage::ALL, ['b']]]);
        $old->start($this->now);
        $old->decide($a, ApprovalStep::APPROVED, 'a', null, 'ok', $this->now);
        $old->decide($b, ApprovalStep::REJECTED, 'b', null, 'no', $this->now);

        $next = new ApprovalRoute($old->getDocument(), 2, $this->version($old->getDocument(), 2), 'author');
        $next->addStage(ApprovalStage::ALL)->carryFrom($old->getStages()[0]);
        $next->addStage(ApprovalStage::ALL)->addStep($b->getAssignee(), ApprovalStep::APPROVE, null, null, null, 'author');
        self::assertSame(2, $next->start($this->now)->getPosition(), 'The carried stage is skipped.');
        self::assertSame(ApprovalStage::CARRIED, $next->getStages()[0]->getStatus());
        self::assertSame(ApprovalStep::CARRIED, $next->getStages()[0]->getSteps()[0]->getStatus());
        self::assertSame('a', $next->getStages()[0]->getSteps()[0]->getDecidedBy());
        self::assertSame(2, $next->getVersionNumber());
    }

    public function test_route_and_stage_validation(): void
    {
        [$route] = $this->route([]);
        $route->addStage(ApprovalStage::ALL);
        $this->expectInvalid(fn () => $route->start($this->now));
        $this->expectInvalid(fn () => $route->addStage('most'));
        $stage = $route->getStages()[0];
        $this->expectInvalid(fn () => $stage->addStep($this->user('x'), 'veto', null, null, null, 'a'));
        for ($i = 0; $i < ApprovalStage::MAX_STEPS; ++$i) {
            $stage->addStep($this->user('p'.$i), ApprovalStep::REVIEW, null, null, null, 'a');
        }
        $this->expectInvalid(fn () => $stage->addStep($this->user('one-more'), ApprovalStep::REVIEW, null, null, null, 'a'));
        for ($i = 1; $i < ApprovalRoute::MAX_STAGES; ++$i) {
            $route->addStage(ApprovalStage::ANY);
        }
        $this->expectInvalid(fn () => $route->addStage(ApprovalStage::ANY));
    }

    public function test_type_route_templates_are_validated(): void
    {
        $type = new DocumentType('ORDER', 'Order');
        $type->configureApproval([['kind' => 'approve', 'level' => 1, 'due_days' => 3], ['kind' => 'sign', 'level' => 2]], true, DocumentType::CONTINUE);
        self::assertSame([['kind' => 'approve', 'level' => 1, 'due_days' => 3], ['kind' => 'sign', 'level' => 2, 'due_days' => null]], $type->getRouteTemplate());
        self::assertTrue($type->autoRegisters());
        self::assertSame(DocumentType::CONTINUE, $type->getResubmitMode());
        foreach ([[['kind' => 'veto', 'level' => 1]], [['kind' => 'approve', 'level' => 0]], [['kind' => 'approve', 'level' => 9]], [['kind' => 'approve', 'level' => 1, 'due_days' => 61]], array_fill(0, 11, ['kind' => 'approve', 'level' => 1])] as $bad) {
            $this->expectInvalid(fn () => $type->configureApproval($bad, false, DocumentType::RESTART));
        }
        $this->expectInvalid(fn () => $type->configureApproval([], false, 'sometimes'));
    }

    public function test_document_status_follows_the_route(): void
    {
        $document = new Document(new DocumentType('MEMO', 'Memo'), $this->user('author'), 'Memo');
        $this->expectDomain(fn () => $document->submitForApproval(), 'no file');
        $document->addVersion();
        $document->submitForApproval();
        self::assertSame(Document::IN_APPROVAL, $document->getStatus());
        self::assertFalse($document->isEditable());
        $this->expectDomain(fn () => $document->submitForApproval());
        $document->concludeApproval(ApprovalRoute::RETURNED);
        self::assertSame(Document::DRAFT, $document->getStatus());
        $this->expectDomain(fn () => $document->concludeApproval(ApprovalRoute::APPROVED));
        foreach ([ApprovalRoute::REJECTED => Document::REJECTED, ApprovalRoute::WITHDRAWN => Document::WITHDRAWN, ApprovalRoute::APPROVED => Document::APPROVED] as $outcome => $status) {
            if ($document->canBeSubmitted()) {
                $document->submitForApproval();
            }
            $document->concludeApproval($outcome);
            self::assertSame($status, $document->getStatus());
        }
        self::assertFalse($document->canBeSubmitted(), 'An approved document is registered, or edited back to draft first.');
    }

    /**
     * @param list<array{0: string, 1: list<string>}> $stages rule and assignee names
     * @return list<mixed> the route followed by every step in order
     */
    private function route(array $stages): array
    {
        $document = new Document(new DocumentType('ORDER', 'Order'), $this->user('author'), 'Order');
        $route = new ApprovalRoute($document, 1, $this->version($document, 1), 'author@example.test');
        $steps = [];
        foreach ($stages as [$rule, $names]) {
            $stage = $route->addStage($rule);
            foreach ($names as $name) {
                $steps[] = $stage->addStep($this->user($name), ApprovalStep::APPROVE, null, null, null, 'author@example.test');
            }
        }

        return [$route, ...$steps];
    }

    private function version(Document $document, int $number): DocumentVersion
    {
        return new DocumentVersion($document, $number, new StoredFile(str_repeat('a', 48), 'f.txt', 'text/plain', 1, str_repeat('b', 64)), $this->user('uploader'), null, null);
    }

    private function user(string $name): User
    {
        $user = (new User())->setEmail($name.'@example.test');
        (new \ReflectionProperty($user, 'id'))->setValue($user, $this->nextId++);

        return $user;
    }

    private function expectDomain(callable $action, string $why = ''): void
    {
        try {
            $action();
            self::fail('Expected a conflict. '.$why);
        } catch (\DomainException) {
            self::addToAssertionCount(1);
        }
    }

    private function expectInvalid(callable $action): void
    {
        try {
            $action();
            self::fail('Invalid input accepted.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
    }
}
