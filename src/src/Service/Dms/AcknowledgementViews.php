<?php

namespace App\Service\Dms;

use App\Entity\AcknowledgementRecipient;
use App\Entity\AcknowledgementRequest;
use App\Entity\OrgUnit;
use App\Entity\User;

/** JSON shapes for acknowledgement requests, recipients, the unit status tree and inbox rows. */
final class AcknowledgementViews
{
    public function __construct(private readonly AcknowledgementService $acknowledgements)
    {
    }

    /**
     * @param list<AcknowledgementRecipient> $recipients the ones the viewer may see
     * @return array<string, mixed>
     */
    public function request(AcknowledgementRequest $request, array $recipients, \DateTimeImmutable $today): array
    {
        $document = $request->getDocument();
        $counts = self::counts($recipients, $today);

        return [
            'id' => $request->getId(),
            'document' => [
                'id' => $document->getId(), 'title' => $document->getTitle(), 'type' => $document->getType()->getName(),
                'registration_number' => $document->getRegistrationNumber(), 'status' => $document->getStatus(), 'confidentiality' => $document->getConfidentiality(),
            ],
            'version_number' => $request->getVersionNumber(),
            'version_sha256' => $request->getVersionSha256(),
            'sender' => $request->getSenderEmail(),
            'message' => $request->getMessage(),
            'due_on' => $request->getDueOn()?->format('Y-m-d'),
            'require_open' => $request->requiresOpening(),
            'auto_add_joiners' => $request->addsJoiners(),
            'targets' => array_map(static fn (array $target): array => ['kind' => $target['kind'], 'id' => $target['id'], 'include_subunits' => $target['include_subunits'], 'label' => $target['label']], $request->getTargets()),
            'status' => $request->getStatus(),
            'is_overdue' => $request->isOpen() && $request->isPastDue($today) && $counts['open'] > 0,
            'previous_id' => $request->getPrevious()?->getId(),
            'created_at' => $request->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'closed_at' => $request->getClosedAt()?->format(\DateTimeInterface::ATOM),
            'closed_by' => $request->getClosedBy(),
            'counts' => $counts,
        ];
    }

    /** @return array<string, mixed> */
    public function recipient(AcknowledgementRecipient $recipient, \DateTimeImmutable $today, ?User $viewer = null): array
    {
        $canSupervise = $viewer !== null && $recipient->getRequest()->isOpen() && $this->acknowledgements->canSupervise($recipient, $viewer);

        return [
            'id' => $recipient->getId(),
            'user' => ['id' => $recipient->getUser()->getId(), 'email' => $recipient->getUserEmail()],
            'unit' => $recipient->getUnitName() !== null ? ['id' => $recipient->getUnit()?->getId(), 'name' => $recipient->getUnitName()] : null,
            'position' => $recipient->getPositionTitle(),
            'source' => $recipient->getSource(),
            'added_by' => $recipient->getAddedBy(),
            'status' => $recipient->getStatus(),
            'is_overdue' => $recipient->isOverdue($today),
            'is_late' => $recipient->isLate(),
            'viewed_at' => $recipient->getViewedAt()?->format(\DateTimeInterface::ATOM),
            'acknowledged_at' => $recipient->getAcknowledgedAt()?->format(\DateTimeInterface::ATOM),
            'acknowledged_sha256' => $recipient->getAcknowledgedSha256(),
            'excused_at' => $recipient->getExcusedAt()?->format(\DateTimeInterface::ATOM),
            'excused_by' => $recipient->getExcusedBy(),
            'excuse_reason' => $recipient->getExcuseReason(),
            'last_reminded_at' => $recipient->getLastRemindedAt()?->format(\DateTimeInterface::ATOM),
            'reminder_count' => $recipient->getReminderCount(),
            'created_at' => $recipient->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'can_remind' => $canSupervise && $recipient->isOpen(),
            'can_excuse' => $canSupervise && $recipient->isOpen(),
            'can_reinstate' => $canSupervise && $recipient->getStatus() === AcknowledgementRecipient::EXCUSED,
        ];
    }

    /** @return array<string, mixed> a row of the person's own inbox or history */
    public function mine(AcknowledgementRecipient $recipient, \DateTimeImmutable $today): array
    {
        $request = $recipient->getRequest();
        $summary = $this->request($request, [], $today);
        unset($summary['counts'], $summary['targets'], $summary['is_overdue']);

        return $this->recipient($recipient, $today) + [
            'request' => $summary,
            'can_acknowledge' => $request->isOpen() && $recipient->getStatus() !== AcknowledgementRecipient::ACKNOWLEDGED && (!$request->requiresOpening() || $recipient->getViewedAt() !== null),
            'needs_opening' => $request->isOpen() && $request->requiresOpening() && $recipient->getViewedAt() === null,
        ];
    }

    /**
     * Completion per unit, rolled up the current structure: each recipient counts in the unit they were recorded
     * under and in every ancestor unit the viewer may see. People recorded without a unit form their own row.
     *
     * @param list<AcknowledgementRecipient> $recipients
     * @param list<int>|null $scope unit ids the viewer may see; null for all
     * @return list<array<string, mixed>> depth-first, siblings by name
     */
    public function unitTree(array $recipients, ?array $scope, \DateTimeImmutable $today): array
    {
        /** @var array<string, array{unit: ?OrgUnit, name: string, own: list<AcknowledgementRecipient>, all: list<AcknowledgementRecipient>}> $nodes */
        $nodes = [];
        foreach ($recipients as $recipient) {
            $unit = $recipient->getUnit();
            if ($unit === null) {
                $nodes['none'] ??= ['unit' => null, 'name' => 'No unit recorded', 'own' => [], 'all' => []];
                $nodes['none']['own'][] = $nodes['none']['all'][] = $recipient;
                continue;
            }
            $first = true;
            for ($current = $unit, $guard = 0; $current !== null && $guard <= OrgUnit::MAX_DEPTH; $current = $current->getParent(), ++$guard) {
                if ($scope !== null && !in_array($current->getId(), $scope, true)) {
                    break;
                }
                $key = (string) $current->getId();
                $nodes[$key] ??= ['unit' => $current, 'name' => $current->getName(), 'own' => [], 'all' => []];
                $nodes[$key]['all'][] = $recipient;
                if ($first) {
                    $nodes[$key]['own'][] = $recipient;
                    $first = false;
                }
            }
        }

        $children = [];
        foreach ($nodes as $key => $node) {
            $parent = null;
            for ($current = $node['unit']?->getParent(); $current !== null; $current = $current->getParent()) {
                if (isset($nodes[(string) $current->getId()])) {
                    $parent = (string) $current->getId();
                    break;
                }
            }
            $children[$parent ?? ''][] = (string) $key;
        }
        foreach ($children as &$list) {
            usort($list, static fn (string $a, string $b): int => [$a === 'none', $nodes[$a]['name']] <=> [$b === 'none', $nodes[$b]['name']]);
        }
        unset($list);

        // Start below ancestors that only pass totals through (no own recipients, a single child), so a request to
        // one department does not show the whole organization above it.
        $top = '';
        $roots = array_values(array_filter($children[''] ?? [], static fn (string $key): bool => $key !== 'none'));
        while (count($roots) === 1 && $nodes[$roots[0]]['own'] === [] && count($children[$roots[0]] ?? []) === 1) {
            $top = $roots[0];
            $roots = $children[$top];
        }
        if ($top !== '') {
            $children[''] = [...$roots, ...array_values(array_filter($children[''], static fn (string $key): bool => $key === 'none'))];
        }

        $rows = [];
        $walk = function (string $parent, int $depth) use (&$walk, &$rows, $children, $nodes, $today): void {
            foreach ($children[$parent] ?? [] as $key) {
                $node = $nodes[$key];
                $rows[] = [
                    'unit_id' => $node['unit']?->getId(),
                    'parent_id' => $parent === '' ? null : (int) $parent,
                    'name' => $node['name'],
                    'depth' => $depth,
                    'own' => self::counts($node['own'], $today),
                    'total' => self::counts($node['all'], $today),
                ];
                $walk($key, $depth + 1);
            }
        };
        $walk('', 0);

        return $rows;
    }

    /**
     * @param list<AcknowledgementRecipient> $recipients
     * @return array{total: int, pending: int, viewed: int, acknowledged: int, excused: int, open: int, overdue: int, late: int, completion_percent: int}
     */
    public static function counts(array $recipients, \DateTimeImmutable $today): array
    {
        $counts = ['total' => count($recipients), 'pending' => 0, 'viewed' => 0, 'acknowledged' => 0, 'excused' => 0, 'open' => 0, 'overdue' => 0, 'late' => 0];
        foreach ($recipients as $recipient) {
            ++$counts[$recipient->getStatus()];
            $counts['open'] += $recipient->isOpen() ? 1 : 0;
            $counts['overdue'] += $recipient->isOverdue($today) ? 1 : 0;
            $counts['late'] += $recipient->isLate() ? 1 : 0;
        }
        $expected = $counts['total'] - $counts['excused'];
        // Whole percent, rounded down, so 100 means everybody expected has confirmed.
        $counts['completion_percent'] = $expected > 0 ? intdiv($counts['acknowledged'] * 100, $expected) : ($counts['total'] > 0 ? 100 : 0);

        return $counts;
    }
}
