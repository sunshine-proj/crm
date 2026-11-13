<?php

namespace App\Service\Dms;

use App\Entity\ApprovalAction;
use App\Entity\ApprovalRoute;
use App\Entity\ApprovalStage;
use App\Entity\ApprovalStep;
use App\Entity\Document;
use App\Entity\DocumentType;
use App\Entity\User;
use App\Repository\ApprovalRouteRepository;
use App\Repository\DocumentVersionRepository;
use App\Repository\UserRepository;
use App\Service\Org\OrgChart;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Approval by subordination. The author (or a registry clerk) sends the current version through sequential stages
 * of parallel steps. Approve and sign steps may only go to the author's superiors (their reporting line); review
 * steps may go to any colleague in the structure. Nobody approves their own document. A deputy acts for an absent
 * assignee and the decision records both names. Decisions lock the route row so parallel approvals are counted once.
 *
 * Exceptions: DocumentAccessDenied (403), NotFound (404), \InvalidArgumentException (400), \DomainException (409).
 */
final class ApprovalService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DocumentAccess $access,
        private readonly DocumentService $documents,
        private readonly ApprovalNotifier $notifier,
        private readonly ApprovalRouteRepository $routeRepository,
        private readonly DocumentVersionRepository $versionRepository,
        private readonly UserRepository $userRepository,
    ) {
    }

    /**
     * Who may be put on the route: the author's superiors (for any step) and colleagues (for review steps), plus
     * the type's template resolved for this author.
     *
     * @return array{superiors: list<array{level: int, user: User}>, colleagues: list<User>, template: list<array{kind: string, level: int, due_on: ?string, user: ?User}>}
     */
    public function candidates(Document $document, User $actor): array
    {
        $this->access->assertManage($actor, $document);
        $chart = $this->access->chartToday();
        $author = $document->getAuthor();
        $superiors = [];
        foreach ($chart->superiors($author) as $index => $superior) {
            $superiors[] = ['level' => $index + 1, 'user' => $superior];
        }

        return ['superiors' => $superiors, 'colleagues' => $this->colleagues($author, $chart), 'template' => $this->resolveTemplate($document->getType(), $author, $chart)];
    }

    /**
     * Starts an approval round. $stages: list of {rule: all|any, steps: list of {assignee_id, kind, due_on?}}. With no
     * stages, the type's route template is used, or (on resubmission) the previous round's route, following the
     * type's resubmission mode.
     *
     * @param list<array<string, mixed>>|null $stages
     */
    public function submit(Document $document, User $actor, ?array $stages, ?string $comment): ApprovalRoute
    {
        $this->access->assertManage($actor, $document);
        $version = $this->versionRepository->findLatest($document) ?? throw new \DomainException('Upload the document file before sending it for approval.');
        $previous = $this->routeRepository->findLatest($document);
        if ($previous?->isActive()) {
            throw new \DomainException('The document is already in approval.');
        }
        $chart = $this->access->chartToday();
        $author = $document->getAuthor();
        $now = new \DateTimeImmutable();
        $route = new ApprovalRoute($document, ($previous?->getRound() ?? 0) + 1, $version, $actor->getUserIdentifier());

        if ($stages !== null && $stages !== []) {
            $this->buildFromInput($route, $stages, $author, $chart, $actor);
        } elseif ($previous !== null) {
            $this->buildFromPrevious($route, $previous, $document->getType()->getResubmitMode() === DocumentType::CONTINUE, $author, $chart, $actor);
        } elseif ($document->getType()->getRouteTemplate() !== []) {
            $this->buildFromTemplate($route, $document->getType(), $author, $chart, $actor);
        } else {
            throw new \InvalidArgumentException('Add at least one stage with a participant.');
        }

        $document->submitForApproval();
        $first = $route->start($now);
        $this->entityManager->persist($route);
        $this->log($route, null, $actor, null, $route->getRound() > 1 ? 'resubmitted' : 'submitted', $comment);
        $this->documents->record($document, $actor, 'submitted_for_approval', ['round' => $route->getRound(), 'version' => $version->getNumber()]);
        $this->announceStage($first, $chart);
        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // A simultaneous submission created this round first.
            throw new \DomainException('The document was just sent for approval by another request.');
        }

        return $route;
    }

    /** Approve, reject or return a step; `review` answers a consultation. */
    public function decide(ApprovalStep $step, User $actor, string $decision, ?string $comment): ApprovalRoute
    {
        $route = $step->getStage()->getRoute();
        $document = $route->getDocument();
        $this->access->assertRead($actor, $document);
        $chart = $this->access->chartToday();
        $onBehalfOf = $this->actingAs($step, $actor, $chart);
        $now = new \DateTimeImmutable();
        $outcome = null;

        $this->entityManager->wrapInTransaction(function () use ($route, $step, $actor, $decision, $comment, $onBehalfOf, $now, $chart, &$outcome): void {
            // Serialize decisions on the same route; re-read it so a parallel decision committed meanwhile is seen.
            $this->entityManager->lock($route, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($route);
            $document = $route->getDocument();
            if ($decision === 'review') {
                $route->review($step, $actor->getUserIdentifier(), $onBehalfOf?->getUserIdentifier(), (string) $comment, $now);
                $this->log($route, $step, $actor, $onBehalfOf, 'reviewed', $comment);
                if ($step->getParent() !== null) {
                    $this->notifier->outcome($step->getParent()->getAssignee(), $document, sprintf('%s reviewed "%s".', $actor->getUserIdentifier(), $document->getTitle()), sprintf('approval:%d:review:%d', $route->getId(), $step->getId()));
                }
                $outcome = 'pending';

                return;
            }
            $map = ['approve' => ApprovalStep::APPROVED, 'reject' => ApprovalStep::REJECTED, 'return' => ApprovalStep::RETURNED];
            if (!isset($map[$decision])) {
                throw new \InvalidArgumentException('Decision must be approve, reject, return or review.');
            }
            $outcome = $route->decide($step, $map[$decision], $actor->getUserIdentifier(), $onBehalfOf?->getUserIdentifier(), $comment, $now);
            $this->log($route, $step, $actor, $onBehalfOf, $decision === 'approve' && trim((string) $comment) !== '' ? 'approved_with_comments' : $map[$decision], $comment);
            if ($outcome === 'next_stage') {
                $this->announceStage($route->currentStage(), $chart);
            } elseif ($outcome !== 'pending') {
                $document->concludeApproval($route->getStatus());
                $this->documents->record($document, $actor, 'approval_'.$route->getStatus(), ['round' => $route->getRound()]);
                $this->notifier->outcome($document->getAuthor(), $document, $this->outcomeMessage($route, $actor, $comment), sprintf('approval:%d:outcome', $route->getId()));
            }
            $this->entityManager->flush();
        });

        if ($outcome === ApprovalRoute::APPROVED && $document->getType()->autoRegisters()) {
            $this->registerAfterApproval($document);
        }

        return $route;
    }

    /** The step's assignee asks one of their own subordinates for an advisory review; the step stays theirs. */
    public function forward(ApprovalStep $step, User $actor, int $consulteeId, ?string $comment, ?\DateTimeImmutable $dueOn): ApprovalStep
    {
        $route = $step->getStage()->getRoute();
        $this->access->assertRead($actor, $route->getDocument());
        $chart = $this->access->chartToday();
        $onBehalfOf = $this->actingAs($step, $actor, $chart);
        $consultee = $this->userRepository->find($consulteeId) ?? throw new NotFound('Person not found.');
        if (!$chart->isSuperiorOf($step->getAssignee(), $consultee)) {
            throw new \DomainException('A review can only be requested from your own subordinates.');
        }
        if ($consultee->getId() === $route->getDocument()->getAuthor()->getId()) {
            throw new \DomainException('The author cannot review their own document.');
        }
        $this->assertDueDate($dueOn);
        $consultation = $route->consult($step, $consultee, ...$this->snapshot($consultee, $chart), dueOn: $dueOn, requestedBy: $actor->getUserIdentifier());
        $this->log($route, $step, $actor, $onBehalfOf, 'forwarded', trim(sprintf('to %s. %s', $consultee->getUserIdentifier(), (string) $comment)));
        $this->notifier->request($consultee, $route->getDocument(), sprintf('%s asks for your review of "%s".', $step->getAssigneeEmail(), $route->getDocument()->getTitle()), sprintf('approval:%d:consult:%d', $route->getId(), $consultee->getId()), $chart);
        $this->entityManager->flush();

        return $consultation;
    }

    public function addReviewer(Document $document, User $actor, int $reviewerId, ?\DateTimeImmutable $dueOn): ApprovalStep
    {
        $this->access->assertManage($actor, $document);
        $route = $this->activeRoute($document);
        $chart = $this->access->chartToday();
        $reviewer = $this->userRepository->find($reviewerId) ?? throw new NotFound('Person not found.');
        $this->assertParticipant($reviewer, ApprovalStep::REVIEW, $document->getAuthor(), $chart);
        $this->assertDueDate($dueOn);
        $step = $route->addReviewer($reviewer, ...$this->snapshot($reviewer, $chart), dueOn: $dueOn, addedBy: $actor->getUserIdentifier());
        $this->log($route, $step, $actor, null, 'reviewer_added', $reviewer->getUserIdentifier());
        $this->notifier->request($reviewer, $document, sprintf('Please review "%s".', $document->getTitle()), sprintf('approval:%d:step:%d', $route->getId(), $reviewer->getId()), $chart);
        $this->entityManager->flush();

        return $step;
    }

    public function withdraw(Document $document, User $actor, ?string $comment): ApprovalRoute
    {
        $this->access->assertManage($actor, $document);
        $route = $this->activeRoute($document);
        $this->entityManager->wrapInTransaction(function () use ($route, $document, $actor, $comment): void {
            $this->entityManager->lock($route, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($route);
            $route->withdraw(new \DateTimeImmutable());
            $document->concludeApproval(ApprovalRoute::WITHDRAWN);
            $this->log($route, null, $actor, null, 'withdrawn', $comment);
            $this->documents->record($document, $actor, 'approval_withdrawn', ['round' => $route->getRound()]);
            $this->entityManager->flush();
        });

        return $route;
    }

    /** People who can act on the step now: its assignee, or the deputy acting for them today. */
    public function canAct(ApprovalStep $step, User $user, OrgChart $chart): bool
    {
        return $step->isOpen() && ($step->getAssignee()->getId() === $user->getId() || $chart->deputyFor($step->getAssignee())?->getId() === $user->getId());
    }

    /** @return User|null the principal when the actor is a deputy, null when they act for themselves */
    private function actingAs(ApprovalStep $step, User $actor, OrgChart $chart): ?User
    {
        if ($step->getAssignee()->getId() === $actor->getId()) {
            return null;
        }
        if ($chart->deputyFor($step->getAssignee())?->getId() === $actor->getId()) {
            return $step->getAssignee();
        }
        throw new DocumentAccessDenied('This approval step is assigned to someone else.');
    }

    /** @param list<array<string, mixed>> $stages */
    private function buildFromInput(ApprovalRoute $route, array $stages, User $author, OrgChart $chart, User $actor): void
    {
        foreach ($stages as $index => $input) {
            if (!is_array($input) || !is_array($input['steps'] ?? null) || $input['steps'] === []) {
                throw new \InvalidArgumentException(sprintf('Stage %d needs at least one participant.', $index + 1));
            }
            $stage = $route->addStage((string) ($input['rule'] ?? ApprovalStage::ALL));
            foreach ($input['steps'] as $stepInput) {
                $kind = (string) ($stepInput['kind'] ?? ApprovalStep::APPROVE);
                $assigneeId = $stepInput['assignee_id'] ?? null;
                if (!is_int($assigneeId)) {
                    throw new \InvalidArgumentException('Every participant needs an assignee_id.');
                }
                $assignee = $this->userRepository->find($assigneeId) ?? throw new NotFound(sprintf('Person %d not found.', $assigneeId));
                $this->assertParticipant($assignee, $kind, $author, $chart);
                $dueOn = $this->parseDue($stepInput['due_on'] ?? null);
                $stage->addStep($assignee, $kind, ...$this->snapshot($assignee, $chart), dueOn: $dueOn, addedBy: $actor->getUserIdentifier());
            }
        }
    }

    private function buildFromTemplate(ApprovalRoute $route, DocumentType $type, User $author, OrgChart $chart, User $actor): void
    {
        foreach ($this->resolveTemplate($type, $author, $chart) as $entry) {
            if ($entry['user'] === null) {
                throw new \DomainException(sprintf('The %s route needs a superior %d level(s) up, but the reporting line is shorter. Choose the participants yourself.', $type->getName(), $entry['level']));
            }
            $route->addStage(ApprovalStage::ALL)->addStep($entry['user'], $entry['kind'], ...$this->snapshot($entry['user'], $chart), dueOn: $entry['due_on'] !== null ? new \DateTimeImmutable($entry['due_on']) : null, addedBy: $actor->getUserIdentifier());
        }
    }

    /** Re-runs the previous round's participants; with `continue`, stages it completed are carried over as done. */
    private function buildFromPrevious(ApprovalRoute $route, ApprovalRoute $previous, bool $continue, User $author, OrgChart $chart, User $actor): void
    {
        foreach ($previous->getStages() as $old) {
            $stage = $route->addStage($old->getRule());
            if ($continue && in_array($old->getStatus(), [ApprovalStage::COMPLETED, ApprovalStage::CARRIED], true)) {
                $stage->carryFrom($old);
                continue;
            }
            foreach ($old->getSteps() as $step) {
                if (!$step->isConsultation()) {
                    $this->copyStep($stage, $step, $author, $chart, $actor);
                }
            }
        }
    }

    private function copyStep(ApprovalStage $stage, ApprovalStep $step, User $author, OrgChart $chart, User $actor): void
    {
        // The structure may have changed since the last round, so the subordination rule is checked again.
        $this->assertParticipant($step->getAssignee(), $step->getKind(), $author, $chart);
        $due = $step->getDueOn();
        $stage->addStep($step->getAssignee(), $step->getKind(), ...$this->snapshot($step->getAssignee(), $chart), dueOn: $due !== null && $due >= new \DateTimeImmutable('today') ? $due : null, addedBy: $actor->getUserIdentifier());
    }

    /** @return list<array{kind: string, level: int, due_on: ?string, user: ?User}> */
    private function resolveTemplate(DocumentType $type, User $author, OrgChart $chart): array
    {
        $superiors = $chart->superiors($author);
        $resolved = [];
        foreach ($type->getRouteTemplate() as $entry) {
            $resolved[] = [
                'kind' => $entry['kind'],
                'level' => $entry['level'],
                'due_on' => $entry['due_days'] !== null ? (new \DateTimeImmutable('today'))->modify(sprintf('+%d days', $entry['due_days']))->format('Y-m-d') : null,
                'user' => $superiors[$entry['level'] - 1] ?? null,
            ];
        }

        return $resolved;
    }

    private function assertParticipant(User $assignee, string $kind, User $author, OrgChart $chart): void
    {
        if (!in_array($kind, ApprovalStep::KINDS, true)) {
            throw new \InvalidArgumentException('A participant reviews, approves or signs.');
        }
        if ($assignee->getId() === $author->getId()) {
            throw new \DomainException('The author cannot approve or review their own document.');
        }
        if (in_array($kind, ApprovalStep::SUPERIOR_KINDS, true) && !$chart->isSuperiorOf($assignee, $author)) {
            throw new \DomainException(sprintf('%s is not in the author\'s reporting line; approvals and signatures go to superiors only.', $assignee->getUserIdentifier()));
        }
        if ($kind === ApprovalStep::REVIEW && $chart->primaryEmployment($assignee) === null) {
            throw new \DomainException(sprintf('%s has no position in the organization structure.', $assignee->getUserIdentifier()));
        }
    }

    /** @return list<User> everyone with a position, except the author, by e-mail */
    private function colleagues(User $author, OrgChart $chart): array
    {
        return array_values(array_filter(
            $this->userRepository->findBy([], ['email' => 'ASC']),
            static fn (User $user): bool => $user->getId() !== $author->getId() && $chart->primaryEmployment($user) !== null,
        ));
    }

    /** @return array{0: ?string, 1: ?string} position title and unit name at this moment */
    private function snapshot(User $user, OrgChart $chart): array
    {
        $employment = $chart->primaryEmployment($user);

        return [$employment?->getPosition()->getTitle(), $employment?->getPosition()->getUnit()->getName()];
    }

    private function parseDue(mixed $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        $date = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('Due dates must use YYYY-MM-DD.');
        }
        $this->assertDueDate($date);

        return $date;
    }

    private function assertDueDate(?\DateTimeImmutable $dueOn): void
    {
        if ($dueOn !== null && $dueOn->format('Y-m-d') < (new \DateTimeImmutable('today'))->format('Y-m-d')) {
            throw new \InvalidArgumentException('A due date cannot be in the past.');
        }
    }

    private function activeRoute(Document $document): ApprovalRoute
    {
        $route = $this->routeRepository->findLatest($document);
        if ($route === null || !$route->isActive()) {
            throw new \DomainException('The document is not in approval.');
        }

        return $route;
    }

    private function announceStage(?ApprovalStage $stage, OrgChart $chart): void
    {
        if ($stage === null) {
            return;
        }
        $route = $stage->getRoute();
        $document = $route->getDocument();
        foreach ($stage->getSteps() as $step) {
            if ($step->isOpen() && !$step->isConsultation()) {
                $verb = ['review' => 'review', 'approve' => 'approve', 'sign' => 'sign'][$step->getKind()];
                $due = $step->getDueOn() !== null ? ' by '.$step->getDueOn()->format('Y-m-d') : '';
                $this->notifier->request($step->getAssignee(), $document, sprintf('Please %s "%s"%s.', $verb, $document->getTitle(), $due), sprintf('approval:%d:stage:%d:user:%d', $route->getId(), $stage->getPosition(), $step->getAssignee()->getId()), $chart);
            }
        }
    }

    private function outcomeMessage(ApprovalRoute $route, User $actor, ?string $comment): string
    {
        $title = $route->getDocument()->getTitle();

        return match ($route->getStatus()) {
            ApprovalRoute::APPROVED => sprintf('"%s" is approved.', $title),
            ApprovalRoute::REJECTED => sprintf('"%s" was rejected by %s: %s', $title, $actor->getUserIdentifier(), (string) $comment),
            default => sprintf('"%s" was returned for revision by %s: %s', $title, $actor->getUserIdentifier(), (string) $comment),
        };
    }

    private function registerAfterApproval(Document $document): void
    {
        try {
            $this->documents->registerAutomatically($document);
        } catch (\DomainException $exception) {
            // The approval stands; a clerk registers manually once the reason is fixed.
            $this->documents->record($document, 'automatic registration', 'registration_failed', ['reason' => $exception->getMessage()]);
            $this->entityManager->flush();
        }
    }

    private function log(ApprovalRoute $route, ?ApprovalStep $step, User $actor, ?User $onBehalfOf, string $action, ?string $comment): void
    {
        $this->entityManager->persist(new ApprovalAction($route, $step, $actor->getUserIdentifier(), $onBehalfOf?->getUserIdentifier(), $action, $comment));
    }
}
