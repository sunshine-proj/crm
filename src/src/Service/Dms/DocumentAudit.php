<?php

namespace App\Service\Dms;

use App\Entity\Document;
use App\Repository\ApprovalActionRepository;
use App\Repository\ApprovalRouteRepository;
use App\Repository\DocumentEventRepository;

/**
 * One document's audit trail: its DocumentEvent rows (views, downloads, versions, access changes, registration,
 * lifecycle, acknowledgements, legal hold, disposal) merged with every approval route action, oldest first.
 * Filters: action prefix, actor substring (case-insensitive), and a date range.
 */
final class DocumentAudit
{
    public const MAX_ENTRIES = 5000;

    public function __construct(
        private readonly DocumentEventRepository $eventRepository,
        private readonly ApprovalRouteRepository $routeRepository,
        private readonly ApprovalActionRepository $actionRepository,
    ) {
    }

    /**
     * @return array{entries: list<array{at: string, source: string, action: string, actor: string, on_behalf_of: ?string, details: array<string, mixed>}>, total: int, truncated: bool}
     */
    public function entries(Document $document, ?string $action, ?string $actor, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to): array
    {
        $rows = [];
        foreach ($this->eventRepository->findForDocument($document) as $event) {
            $rows[] = ['at' => $event->getCreatedAt(), 'id' => $event->getId(), 'source' => 'document', 'action' => $event->getAction(), 'actor' => $event->getActor(), 'on_behalf_of' => null, 'details' => $event->getDetails()];
        }
        $routes = $this->routeRepository->findForDocument($document);
        foreach ($this->actionRepository->findForRoutes($routes) as $actions) {
            foreach ($actions as $item) {
                $rows[] = ['at' => $item->getCreatedAt(), 'id' => $item->getId(), 'source' => 'approval', 'action' => 'approval_'.$item->getAction(), 'actor' => $item->getActor(), 'on_behalf_of' => $item->getOnBehalfOf(),
                    'details' => ['round' => $item->getRoute()->getRound(), 'step' => $item->getStep()?->getId(), 'comment' => $item->getComment(), 'version' => $item->getVersionNumber(), 'sha256' => $item->getVersionSha256()]];
            }
        }
        $action = $action !== null ? mb_strtolower(trim($action)) : '';
        $actor = $actor !== null ? mb_strtolower(trim($actor)) : '';
        $rows = array_values(array_filter($rows, static fn (array $row): bool => ($action === '' || str_starts_with($row['action'], $action))
            && ($actor === '' || str_contains(mb_strtolower($row['actor'].' '.($row['on_behalf_of'] ?? '')), $actor))
            && ($from === null || $row['at'] >= $from->setTime(0, 0))
            && ($to === null || $row['at'] < $to->setTime(0, 0)->modify('+1 day'))));
        usort($rows, static fn (array $a, array $b): int => [$a['at'], $a['source'], $a['id']] <=> [$b['at'], $b['source'], $b['id']]);
        $total = count($rows);

        return [
            'entries' => array_map(static fn (array $row): array => [
                'at' => $row['at']->format(\DateTimeInterface::ATOM), 'source' => $row['source'], 'action' => $row['action'],
                'actor' => $row['actor'], 'on_behalf_of' => $row['on_behalf_of'], 'details' => $row['details'],
            ], array_slice($rows, 0, self::MAX_ENTRIES)),
            'total' => $total,
            'truncated' => $total > self::MAX_ENTRIES,
        ];
    }
}
