<?php

namespace App\Service\Module;

/**
 * The functional modules an administrator can switch on or off for this installation, which API routes and
 * scheduled jobs belong to them, what they depend on, and the settings they expose. Core routes (sign-in, users,
 * notes, notifications, history, dashboard, organization settings and modules) are never switched off.
 *
 * Routes are assigned by route-name prefix (longest match wins); ROUTE_REQUIREMENTS adds modules a single route
 * also needs, for example creating an invoice from approved time needs both invoices and time tracking.
 */
final class ModuleCatalog
{
    /** @var array<string, array{label: string, description: string, requires: list<string>, route_prefixes: list<string>}> */
    public const MODULES = [
        'clients' => [
            'label' => 'Clients', 'description' => 'Client records, contacts and the client activity timeline.',
            'requires' => [], 'route_prefixes' => ['api_clients_', 'api_client_contacts_', 'api_client_activities_'],
        ],
        'news' => [
            'label' => 'News', 'description' => 'Internal news items on the dashboard.',
            'requires' => [], 'route_prefixes' => ['api_news_'],
        ],
        'sales_pipeline' => [
            'label' => 'Deals', 'description' => 'Sales pipeline: deals, stages and win/loss reasons.',
            'requires' => ['clients'], 'route_prefixes' => ['api_deals_'],
        ],
        'projects' => [
            'label' => 'Project management', 'description' => 'Projects, milestones, tasks, the timetable and the task board.',
            'requires' => ['clients'], 'route_prefixes' => ['api_projects_', 'api_tasks_'],
        ],
        'time_tracking' => [
            'label' => 'Time tracking', 'description' => 'Time entries and approval, timesheet, task timer, time report and workload.',
            'requires' => ['projects'], 'route_prefixes' => ['api_operations_time_', 'api_operations_user_cost_rate'],
        ],
        'project_operations' => [
            'label' => 'Project expenses', 'description' => 'Project expenses and reimbursements, project documents and profitability.',
            'requires' => ['projects'], 'route_prefixes' => ['api_operations_expense', 'api_operations_documents_', 'api_operations_project_profitability'],
        ],
        'sales' => [
            'label' => 'Sales', 'description' => 'Product catalog, quotes, sales orders and shipments.',
            'requires' => ['clients'], 'route_prefixes' => ['api_sales_products_', 'api_sales_quotes_', 'api_sales_orders_', 'api_sales_shipments_'],
        ],
        'inventory' => [
            'label' => 'Inventory and procurement', 'description' => 'Warehouses, suppliers, purchase orders and approval, receipts, stock, dispatch, returns and valuation.',
            'requires' => ['sales'], 'route_prefixes' => ['api_inventory_', 'api_purchasing_'],
        ],
        'invoices' => [
            'label' => 'Invoices', 'description' => 'Customer invoices, PDFs, credit notes and payments.',
            'requires' => ['clients'], 'route_prefixes' => ['api_invoices_', 'api_credit_notes_', 'api_sales_payments_'],
        ],
        'accounting' => [
            'label' => 'Accounting', 'description' => 'Ledger, fiscal periods, journals, payables, tax, jurisdiction rules, statements and reconciliation; automatic postings.',
            'requires' => [], 'route_prefixes' => ['api_finance_', 'api_jurisdiction_'],
        ],
        'bank' => [
            'label' => 'Bank', 'description' => 'Bank accounts linked to ledger accounts, statement import and matching to receipts and payments.',
            'requires' => ['invoices', 'accounting'], 'route_prefixes' => ['api_bank_'],
        ],
        'organization_structure' => [
            'label' => 'Organization structure', 'description' => 'Units, positions, employments, reporting lines and deputies.',
            'requires' => [], 'route_prefixes' => ['api_org_', 'api_delegations_'],
        ],
        'document_management' => [
            'label' => 'Document management', 'description' => 'Document registry, approval routes, acknowledgements and records management.',
            'requires' => ['organization_structure'], 'route_prefixes' => ['api_dms_'],
        ],
    ];

    /** Route-name prefixes that are always available. */
    public const CORE_ROUTE_PREFIXES = [
        'api_login', 'api_dashboard_', 'api_notes_',
        'api_notifications_', 'api_history_', 'api_records_', 'api_organization_settings_', 'api_modules_',
        // Shared lookups used by several modules: the user picker and the currency list.
        'api_projects_managers', 'api_finance_currencies',
    ];

    /** Single routes that also need another module. */
    public const ROUTE_REQUIREMENTS = [
        'api_invoices_from_approved_time' => ['time_tracking'],
        'api_invoices_from_milestone' => ['projects'],
        'api_invoices_create' => ['projects'],
        'api_sales_orders_invoice' => ['invoices'],
    ];

    /** Roles an administrator may restrict a module to (administrators always see enabled modules). */
    public const RESTRICTABLE_ROLES = ['ROLE_FINANCE', 'ROLE_INVENTORY', 'ROLE_DMS_ADMIN'];

    /**
     * Per-module settings with their type, range and default (today's behavior).
     *
     * @var array<string, array<string, array{label: string, type: 'int'|'bool', min?: int, max?: int, default: int|bool}>>
     */
    public const SETTINGS = [
        'time_tracking' => [
            'weekly_capacity_hours' => ['label' => 'Standard weekly capacity (hours) for the workload view', 'type' => 'int', 'min' => 1, 'max' => 80, 'default' => 40],
            'missing_time_reminders' => ['label' => 'Remind people who logged no time on the previous working day', 'type' => 'bool', 'default' => true],
        ],
        'project_operations' => [
            'budget_warning_percent' => ['label' => 'Warn when approved cost reaches this share of the budget (%)', 'type' => 'int', 'min' => 1, 'max' => 100, 'default' => 80],
        ],
    ];

    public static function exists(string $key): bool
    {
        return isset(self::MODULES[$key]);
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::MODULES);
    }

    /** @return list<string> modules that list $key as a dependency */
    public static function dependents(string $key): array
    {
        return array_keys(array_filter(self::MODULES, static fn (array $module): bool => in_array($key, $module['requires'], true)));
    }

    /**
     * Modules a route needs: an empty list for core routes, null for routes this catalog does not know
     * (non-API routes such as the profiler).
     *
     * @return list<string>|null
     */
    public static function modulesForRoute(string $route): ?array
    {
        $best = null;
        $bestLength = -1;
        foreach (self::CORE_ROUTE_PREFIXES as $prefix) {
            if (str_starts_with($route, $prefix) && strlen($prefix) > $bestLength) {
                [$best, $bestLength] = [null, strlen($prefix)];
            }
        }
        $core = $bestLength >= 0;
        foreach (self::MODULES as $key => $module) {
            foreach ($module['route_prefixes'] as $prefix) {
                if (str_starts_with($route, $prefix) && strlen($prefix) > $bestLength) {
                    [$best, $bestLength, $core] = [$key, strlen($prefix), false];
                }
            }
        }
        if ($core) {
            return [];
        }
        if ($best === null) {
            return null;
        }

        return array_values(array_unique([$best, ...(self::ROUTE_REQUIREMENTS[$route] ?? [])]));
    }
}
