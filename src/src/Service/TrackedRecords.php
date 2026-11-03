<?php

namespace App\Service;

use App\Entity\Client;
use App\Entity\ClientContact;
use App\Entity\Deal;
use App\Entity\Product;
use App\Entity\Project;
use App\Entity\ProjectMilestone;
use App\Entity\Supplier;
use App\Entity\Task;
use App\Entity\Warehouse;

/**
 * Business records whose changes are versioned and recoverable. Invoices, journals, payments, stock movements and
 * other financial or append-only records are intentionally excluded: they are corrected by their own
 * reversal/credit workflows, never by rolling a row back.
 */
final class TrackedRecords
{
    /** @var array<string, class-string> */
    private const TYPES = [
        'client' => Client::class,
        'client_contact' => ClientContact::class,
        'deal' => Deal::class,
        'project' => Project::class,
        'project_milestone' => ProjectMilestone::class,
        'task' => Task::class,
        'product' => Product::class,
        'supplier' => Supplier::class,
        'warehouse' => Warehouse::class,
    ];

    /** @return list<string> */
    public function types(): array
    {
        return array_keys(self::TYPES);
    }

    /** @return class-string|null */
    public function classFor(string $type): ?string
    {
        return self::TYPES[$type] ?? null;
    }

    public function typeFor(object $entity): ?string
    {
        $type = array_search($entity::class, self::TYPES, true);

        return $type === false ? null : $type;
    }
}
