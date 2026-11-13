<?php

namespace App\Service\Dms;

use App\Entity\ApprovalAction;
use App\Entity\ApprovalRoute;
use App\Entity\ApprovalStage;
use App\Entity\ApprovalStep;
use App\Entity\User;
use App\Service\Org\OrgChart;

/** JSON shapes for approval routes, inbox rows and history (snake_case, ISO 8601 / YYYY-MM-DD). */
final class ApprovalViews
{
    public function __construct(private readonly ApprovalService $approvals)
    {
    }

    /**
     * @param list<ApprovalAction> $actions
     * @return array<string, mixed>
     */
    public function route(ApprovalRoute $route, array $actions, User $viewer, OrgChart $chart): array
    {
        $today = new \DateTimeImmutable('today');

        return [
            'id' => $route->getId(),
            'round' => $route->getRound(),
            'status' => $route->getStatus(),
            'version_number' => $route->getVersionNumber(),
            'version_sha256' => $route->getVersionSha256(),
            'created_by' => $route->getCreatedBy(),
            'created_at' => $route->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'completed_at' => $route->getCompletedAt()?->format(\DateTimeInterface::ATOM),
            'stages' => array_map(fn (ApprovalStage $stage): array => [
                'id' => $stage->getId(),
                'position' => $stage->getPosition(),
                'rule' => $stage->getRule(),
                'status' => $stage->getStatus(),
                'activated_at' => $stage->getActivatedAt()?->format(\DateTimeInterface::ATOM),
                'completed_at' => $stage->getCompletedAt()?->format(\DateTimeInterface::ATOM),
                'steps' => array_map(fn (ApprovalStep $step): array => $this->step($step, $viewer, $chart, $today), $stage->getSteps()),
            ], $route->getStages()),
            'actions' => array_map(static fn (ApprovalAction $action): array => [
                'id' => $action->getId(),
                'action' => $action->getAction(),
                'actor' => $action->getActor(),
                'on_behalf_of' => $action->getOnBehalfOf(),
                'comment' => $action->getComment(),
                'step_id' => $action->getStep()?->getId(),
                'version_number' => $action->getVersionNumber(),
                'version_sha256' => $action->getVersionSha256(),
                'created_at' => $action->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ], $actions),
        ];
    }

    /** @return array<string, mixed> */
    public function step(ApprovalStep $step, User $viewer, OrgChart $chart, \DateTimeImmutable $today): array
    {
        $canAct = $this->approvals->canAct($step, $viewer, $chart) && $step->getStage()->getStatus() === ApprovalStage::ACTIVE && $step->getStage()->getRoute()->isActive();

        return [
            'id' => $step->getId(),
            'parent_id' => $step->getParent()?->getId(),
            'kind' => $step->getKind(),
            'status' => $step->getStatus(),
            'assignee' => ['id' => $step->getAssignee()->getId(), 'email' => $step->getAssigneeEmail(), 'position' => $step->getAssigneePosition(), 'unit' => $step->getAssigneeUnit()],
            'due_on' => $step->getDueOn()?->format('Y-m-d'),
            'is_overdue' => $step->isOverdue($today),
            'opened_at' => $step->getOpenedAt()?->format(\DateTimeInterface::ATOM),
            'decided_at' => $step->getDecidedAt()?->format(\DateTimeInterface::ATOM),
            'decided_by' => $step->getDecidedBy(),
            'on_behalf_of' => $step->getOnBehalfOf(),
            'comment' => $step->getComment(),
            'added_by' => $step->getAddedBy(),
            'can_act' => $canAct,
            'acting_for' => $canAct && $step->getAssignee()->getId() !== $viewer->getId() ? $step->getAssigneeEmail() : null,
        ];
    }

    /** @return array<string, mixed> one inbox row: the open step and the document it belongs to */
    public function inboxRow(ApprovalStep $step, User $viewer, OrgChart $chart, \DateTimeImmutable $today): array
    {
        $route = $step->getStage()->getRoute();
        $document = $route->getDocument();

        return $this->step($step, $viewer, $chart, $today) + [
            'document' => [
                'id' => $document->getId(), 'title' => $document->getTitle(), 'type' => $document->getType()->getName(),
                'author' => $document->getAuthor()->getUserIdentifier(), 'confidentiality' => $document->getConfidentiality(),
            ],
            'round' => $route->getRound(),
            'stage_position' => $step->getStage()->getPosition(),
            'stage_count' => count($route->getStages()),
            'requested_by' => $step->getParent()?->getAssigneeEmail(),
        ];
    }
}
