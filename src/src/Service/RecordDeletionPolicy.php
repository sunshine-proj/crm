<?php

namespace App\Service;

use App\Entity\Client;
use App\Entity\Project;
use App\Repository\ExpenseRepository;
use App\Repository\ProjectDocumentRepository;
use App\Repository\SalesDocumentRepository;
use App\Repository\TimeEntryRepository;

/**
 * Decides whether a client or project may be deleted. Deleting must never silently destroy or orphan
 * financial source data: time entries and uploaded documents would vanish through database cascades, and
 * expenses or sales documents would fail the delete with a foreign-key error.
 */
final class RecordDeletionPolicy
{
    public function __construct(
        private readonly TimeEntryRepository $timeEntries,
        private readonly ExpenseRepository $expenses,
        private readonly ProjectDocumentRepository $documents,
        private readonly SalesDocumentRepository $salesDocuments,
    ) {
    }

    /** @return list<string> what still depends on the project; empty when it may be deleted */
    public function projectBlockers(Project $project): array
    {
        return $this->describe([
            'time entries' => $this->timeEntries->count(['project' => $project]),
            'expenses' => $this->expenses->count(['project' => $project]),
            'documents' => $this->documents->count(['project' => $project]),
        ]);
    }

    /** @return list<string> what still depends on the client; empty when it may be deleted */
    public function clientBlockers(Client $client): array
    {
        return $this->describe(['quotes or orders' => $this->salesDocuments->count(['client' => $client])]);
    }

    /**
     * @param array<string, int> $counts
     * @return list<string>
     */
    private function describe(array $counts): array
    {
        $blockers = [];
        foreach (array_filter($counts) as $label => $count) {
            $blockers[] = sprintf('%d %s', $count, $label);
        }

        return $blockers;
    }
}
