<?php

namespace App\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Role\RoleHierarchy;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Pins the declared role requirement of every API route. A new route fails this test until it is
 * classified, which forces an explicit least-privilege decision. Routes that also enforce record-level
 * rules inline (ownership, project manager, submitter) are listed in RECORD_LEVEL.
 */
final class ApiAccessPolicyTest extends TestCase
{
    /** Routes any authenticated user may call. */
    private const USER_ROUTES = [
        'api_client_activities_list', 'api_client_activities_create',
        'api_client_contacts_list', 'api_clients_list', 'api_clients_show', 'api_dashboard_summary',
        'api_finance_currencies',
        'api_inventory_warehouses_list', 'api_inventory_warehouses_create', 'api_inventory_warehouses_update',
        'api_inventory_suppliers_list', 'api_inventory_suppliers_create', 'api_inventory_suppliers_update',
        'api_inventory_purchase_orders_list', 'api_inventory_purchase_orders_create', 'api_inventory_goods_receipt_create',
        'api_inventory_stock_list', 'api_inventory_movements_list', 'api_inventory_pending_sales_shipments',
        'api_inventory_dispatched_sales_shipments', 'api_inventory_sales_shipment_dispatch',
        'api_inventory_returns_list', 'api_inventory_returns_create',
        'api_invoices_list', 'api_invoices_create', 'api_invoices_from_approved_time', 'api_invoices_from_milestone',
        'api_invoices_show', 'api_invoices_update', 'api_invoices_pdf', 'api_invoices_delete',
        'api_deals_list', 'api_deals_summary', 'api_deals_create', 'api_deals_show', 'api_deals_update', 'api_deals_stage', 'api_deals_history',
        'api_notifications_list', 'api_notifications_read', 'api_notifications_read_all', 'api_notifications_preferences_show', 'api_notifications_preferences_update',
        'api_history_show', 'api_purchasing_history',
        'api_notes_list', 'api_notes_create', 'api_notes_update', 'api_notes_delete',
        'api_operations_time_entries_list', 'api_operations_time_entries_create', 'api_operations_time_entry_decision',
        'api_operations_time_entry_reopen', 'api_operations_time_entry_withdraw', 'api_operations_time_entry_history',
        'api_operations_expenses_list', 'api_operations_expenses_create', 'api_operations_expenses_pending',
        'api_operations_expense_decision', 'api_operations_expense_reimbursements_list',
        'api_operations_documents_list', 'api_operations_documents_upload', 'api_operations_documents_download',
        'api_operations_documents_delete',
        'api_organization_settings_show',
        'api_projects_list', 'api_projects_managers', 'api_projects_create', 'api_projects_show',
        'api_projects_milestones_list', 'api_projects_milestones_create', 'api_projects_milestones_complete',
        'api_projects_update', 'api_projects_delete',
        'api_sales_products_list', 'api_sales_quotes_list', 'api_sales_quotes_create', 'api_sales_quotes_status',
        'api_sales_quotes_convert', 'api_sales_orders_list', 'api_sales_shipments_create', 'api_sales_orders_invoice',
        'api_tasks_list', 'api_tasks_create', 'api_tasks_show', 'api_tasks_dependencies_list',
        'api_tasks_dependencies_update', 'api_tasks_update', 'api_tasks_delete',
    ];

    /** Routes that require ROLE_FINANCE (administrators inherit it). */
    private const FINANCE_ROUTES = [
        'api_finance_account_mappings',
        'api_finance_accounts_list',
        'api_finance_periods_list',
        'api_finance_journal_list',
        'api_finance_journal_create',
        'api_finance_tax_list',
        'api_finance_payables_list',
        'api_finance_payables_create',
        'api_finance_payables_payment',
        'api_finance_trial_balance',
        'api_finance_financial_statements',
        'api_finance_reconciliation',
        'api_finance_ar_ap',
        'api_finance_subledger_reconciliation',
        'api_invoices_issue',
        'api_inventory_valuation_report', 'api_inventory_valuation_opening',
        'api_purchasing_policy_show', 'api_purchasing_approve', 'api_purchasing_reject',
        'api_operations_project_profitability',
        'api_operations_expense_reimbursements_create',
        'api_sales_products_create',
        'api_sales_products_update',
        'api_sales_payments_list',
        'api_sales_payments_create',
    ];

    /** Routes that require ROLE_ADMIN. */
    private const ADMIN_ROUTES = [
        'api_client_contacts_create', 'api_client_contacts_update', 'api_client_contacts_delete',
        'api_clients_create', 'api_clients_update', 'api_clients_delete',
        'api_finance_account_mappings_update',
        'api_finance_accounts_create', 'api_finance_accounts_update',
        'api_finance_periods_create', 'api_finance_periods_close', 'api_finance_periods_reopen',
        'api_finance_opening_balance', 'api_finance_journal_reverse',
        'api_finance_tax_create',


        'api_inventory_adjustment_create',
        'api_deals_delete', 'api_purchasing_policy_update',
        'api_records_revisions_list', 'api_records_revisions_restore',

        'api_operations_user_cost_rate',
        'api_organization_settings_update', 'api_organization_settings_restore',

    ];

    /** Routes whose authorization is an ownership check inside the controller. */
    private const RECORD_LEVEL = [
        'api_news_list', 'api_news_create', 'api_news_show', 'api_news_update', 'api_news_delete',
    ];

    /** Routes reachable without a token. */
    private const PUBLIC_ROUTES = ['api_login'];

    public function test_every_api_route_is_classified_and_declares_the_expected_role(): void
    {
        $routes = $this->declaredRoutes();
        $classified = [...self::USER_ROUTES, ...self::FINANCE_ROUTES, ...self::ADMIN_ROUTES, ...self::RECORD_LEVEL, ...self::PUBLIC_ROUTES];

        self::assertSame([], array_values(array_diff(array_keys($routes), $classified)), 'Unclassified route: add it to the access policy.');
        self::assertSame([], array_values(array_diff($classified, array_keys($routes))), 'Policy lists a route that no longer exists.');
        self::assertSame(array_unique($classified), $classified, 'A route is classified twice.');

        foreach (self::FINANCE_ROUTES as $name) {
            self::assertContains('ROLE_FINANCE', $routes[$name], sprintf('%s must require ROLE_FINANCE.', $name));
        }
        foreach (self::ADMIN_ROUTES as $name) {
            self::assertContains('ROLE_ADMIN', $routes[$name], sprintf('%s must require ROLE_ADMIN.', $name));
        }
        foreach (self::USER_ROUTES as $name) {
            self::assertContains('ROLE_USER', $routes[$name], sprintf('%s must require at least ROLE_USER.', $name));
            self::assertNotContains('ROLE_ADMIN', $routes[$name], sprintf('%s is documented as open to all users.', $name));
            self::assertNotContains('ROLE_FINANCE', $routes[$name], sprintf('%s is documented as open to all users.', $name));
        }
    }

    public function test_finance_ledger_routes_other_than_currency_list_need_a_finance_or_admin_role(): void
    {
        foreach ($this->declaredRoutes() as $name => $roles) {
            if (str_starts_with($name, 'api_finance_') && $name !== 'api_finance_currencies') {
                self::assertNotSame([], array_intersect($roles, ['ROLE_FINANCE', 'ROLE_ADMIN']), sprintf('%s exposes ledger data.', $name));
            }
        }
    }

    public function test_configuration_and_period_control_stay_administrator_only(): void
    {
        $routes = $this->declaredRoutes();
        foreach (['api_finance_account_mappings_update', 'api_finance_periods_close', 'api_finance_periods_reopen', 'api_finance_journal_reverse', 'api_finance_opening_balance', 'api_operations_user_cost_rate'] as $name) {
            self::assertContains('ROLE_ADMIN', $routes[$name], $name);
        }
    }

    public function test_role_hierarchy_lets_administrators_act_as_finance_but_not_the_reverse(): void
    {
        $hierarchy = new RoleHierarchy(['ROLE_FINANCE' => ['ROLE_USER'], 'ROLE_ADMIN' => ['ROLE_FINANCE', 'ROLE_USER']]);

        self::assertContains('ROLE_FINANCE', $hierarchy->getReachableRoleNames(['ROLE_ADMIN']));
        self::assertNotContains('ROLE_ADMIN', $hierarchy->getReachableRoleNames(['ROLE_FINANCE']));
        self::assertContains('ROLE_USER', $hierarchy->getReachableRoleNames(['ROLE_FINANCE']));
    }

    /** @return array<string, list<string>> route name => roles required by class and method attributes */
    private function declaredRoutes(): array
    {
        $routes = [];
        foreach (glob(dirname(__DIR__, 3).'/src/Controller/*Controller.php') ?: [] as $file) {
            $class = new \ReflectionClass('App\\Controller\\'.basename($file, '.php'));
            $classRoute = $class->getAttributes(Route::class)[0] ?? null;
            $prefix = $classRoute?->newInstance()->name ?? '';
            $classRoles = $this->roles($class->getAttributes(IsGranted::class));
            foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                $routeAttribute = $method->getAttributes(Route::class)[0] ?? null;
                if ($routeAttribute === null) {
                    continue;
                }
                $name = $prefix.$routeAttribute->newInstance()->name;
                $routes[$name] = [...$classRoles, ...$this->roles($method->getAttributes(IsGranted::class))];
            }
        }

        return $routes;
    }

    /**
     * @param list<\ReflectionAttribute> $attributes
     * @return list<string>
     */
    private function roles(array $attributes): array
    {
        return array_map(static fn (\ReflectionAttribute $attribute): string => (string) $attribute->newInstance()->attribute, $attributes);
    }
}
