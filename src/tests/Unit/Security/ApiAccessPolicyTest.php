<?php

namespace App\Tests\Unit\Security;

use App\Command\SetUserRolesCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Role\RoleHierarchy;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Yaml\Yaml;

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
        'api_inventory_warehouses_list', 'api_inventory_suppliers_list', 'api_inventory_purchase_orders_list',
        'api_inventory_stock_list', 'api_inventory_movements_list', 'api_inventory_pending_sales_shipments',
        'api_inventory_dispatched_sales_shipments', 'api_inventory_returns_list',
        'api_invoices_list', 'api_invoices_create', 'api_invoices_from_approved_time', 'api_invoices_from_milestone',
        'api_invoices_show', 'api_invoices_update', 'api_invoices_pdf', 'api_invoices_delete',
        'api_deals_list', 'api_deals_summary', 'api_deals_create', 'api_deals_show', 'api_deals_update', 'api_deals_stage', 'api_deals_history',
        'api_notifications_list', 'api_notifications_read', 'api_notifications_read_all', 'api_notifications_preferences_show', 'api_notifications_preferences_update',
        'api_history_show', 'api_purchasing_history',
        'api_notes_list', 'api_notes_create',
        'api_operations_time_entries_list', 'api_operations_time_entries_create', 'api_operations_time_entry_decision',
        'api_operations_time_entry_reopen', 'api_operations_time_entry_withdraw', 'api_operations_time_entry_history',
        // Time reports and the timesheet batch: data is limited inline by TimeVisibility (finance/admin all, managers their projects, others their own).
        'api_operations_time_entries_batch', 'api_operations_time_report', 'api_operations_time_report_export', 'api_operations_time_report_workload',
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
        'api_org_units_list', 'api_org_units_members', 'api_org_positions_list', 'api_org_employments_list',
        'api_org_people', 'api_org_me', 'api_org_reporting_line',
        // Delegations: own records only; naming a deputy for someone else, listing all and revoking others' need ROLE_ADMIN inline.
        'api_delegations_list', 'api_delegations_create', 'api_delegations_revoke',
        'api_dms_types_list',
        // Documents: author, named readers, owner-unit heads and registry clerks decide access inline (DocumentAccess).
        'api_dms_documents_list', 'api_dms_documents_create', 'api_dms_documents_show', 'api_dms_documents_update',
        'api_dms_documents_delete', 'api_dms_documents_versions_upload', 'api_dms_documents_versions_file',
        'api_dms_documents_checkout', 'api_dms_documents_checkin', 'api_dms_documents_readers',
        'api_dms_documents_attachments_upload', 'api_dms_documents_attachments_file', 'api_dms_documents_attachments_delete',
        'api_dms_documents_links_create', 'api_dms_documents_links_delete', 'api_dms_documents_card_pdf',
        'api_dms_documents_review', 'api_dms_documents_audit',
        // Approval: assignee or deputy, author or clerk, checked inline by ApprovalService.
        'api_dms_approvals_inbox', 'api_dms_approvals_counts', 'api_dms_approvals_decide', 'api_dms_approvals_forward',
        'api_dms_approvals_forward_candidates', 'api_dms_approvals_history', 'api_dms_approvals_candidates',
        'api_dms_approvals_submit', 'api_dms_approvals_withdraw', 'api_dms_approvals_reviewers',
        'api_dms_review_comments_list', 'api_dms_review_comments_create', 'api_dms_review_comments_resolve', 'api_dms_review_compare',
        // Acknowledgement: senders (unit heads, clerks), managers of affected units and recipients, checked inline by AcknowledgementService.
        'api_dms_ack_inbox', 'api_dms_ack_counts', 'api_dms_ack_history', 'api_dms_ack_list', 'api_dms_ack_show', 'api_dms_ack_export', 'api_dms_ack_sheet',
        'api_dms_ack_remind', 'api_dms_ack_deadline', 'api_dms_ack_recipients', 'api_dms_ack_close', 'api_dms_ack_reissue', 'api_dms_ack_acknowledge',
        'api_dms_ack_excuse', 'api_dms_ack_reinstate', 'api_dms_ack_document', 'api_dms_ack_audience', 'api_dms_ack_send',
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
        'api_bank_accounts', 'api_bank_account_create', 'api_bank_imports', 'api_bank_import', 'api_bank_lines', 'api_bank_suggestions',
        'api_bank_match', 'api_bank_unmatch', 'api_bank_ignore', 'api_bank_auto_match', 'api_bank_reconciliation',
        'api_credit_notes_list', 'api_credit_notes_create', 'api_credit_notes_pdf',
        'api_jurisdiction_overview', 'api_jurisdiction_validations', 'api_jurisdiction_legal_profile',
        'api_inventory_valuation_report', 'api_inventory_valuation_opening',
        'api_purchasing_policy_show', 'api_purchasing_approve', 'api_purchasing_reject',
        'api_operations_project_profitability',
        'api_operations_expense_reimbursements_create',
        'api_sales_products_create',
        'api_sales_products_update',
        'api_sales_payments_list',
        'api_sales_payments_create',
    ];

    /** Stock-changing routes that require ROLE_INVENTORY (administrators inherit it; finance users do not). */
    private const INVENTORY_ROUTES = [
        'api_inventory_purchase_orders_create', 'api_inventory_goods_receipt_create',
        'api_inventory_sales_shipment_dispatch', 'api_inventory_returns_create',
    ];

    /** Registry routes that require ROLE_DMS_ADMIN (administrators inherit it). */
    private const DMS_ADMIN_ROUTES = [
        'api_dms_types_create', 'api_dms_types_update', 'api_dms_documents_register', 'api_dms_documents_archive',
        'api_dms_documents_legal_hold_place', 'api_dms_documents_legal_hold_release',
        'api_dms_records_disposals_eligible', 'api_dms_records_disposals_list', 'api_dms_records_disposals_propose', 'api_dms_records_reports',
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
        'api_inventory_warehouses_create', 'api_inventory_warehouses_update',
        'api_inventory_suppliers_create', 'api_inventory_suppliers_update',
        'api_deals_delete', 'api_purchasing_policy_update',
        'api_jurisdiction_validation_create', 'api_jurisdiction_validation_revoke', 'api_jurisdiction_standard_tax_code', 'api_jurisdiction_legal_profile_update',
        'api_records_revisions_list', 'api_records_revisions_restore',

        'api_operations_user_cost_rate',
        'api_organization_settings_update', 'api_organization_settings_restore',
        'api_org_units_create', 'api_org_units_update', 'api_org_units_archive', 'api_org_units_restore', 'api_org_units_delete',
        'api_org_positions_create', 'api_org_positions_update', 'api_org_positions_archive', 'api_org_positions_delete',
        'api_org_employments_create', 'api_org_employments_update', 'api_org_employments_delete',
        // Disposal decisions: an administrator other than the proposing clerk (checked in DisposalService).
        'api_dms_records_disposals_approve', 'api_dms_records_disposals_reject',

    ];

    /** Routes whose authorization is an ownership check inside the controller. */
    private const RECORD_LEVEL = [
        'api_news_list', 'api_news_create', 'api_news_show', 'api_news_update', 'api_news_delete',
        'api_notes_update', 'api_notes_delete',
    ];

    /** Routes reachable without a token. */
    private const PUBLIC_ROUTES = ['api_login'];

    public function test_every_api_route_is_classified_and_declares_the_expected_role(): void
    {
        $routes = $this->declaredRoutes();
        $classified = [...self::USER_ROUTES, ...self::FINANCE_ROUTES, ...self::INVENTORY_ROUTES, ...self::DMS_ADMIN_ROUTES, ...self::ADMIN_ROUTES, ...self::RECORD_LEVEL, ...self::PUBLIC_ROUTES];

        self::assertSame([], array_values(array_diff(array_keys($routes), $classified)), 'Unclassified route: add it to the access policy.');
        self::assertSame([], array_values(array_diff($classified, array_keys($routes))), 'Policy lists a route that no longer exists.');
        self::assertSame(array_unique($classified), $classified, 'A route is classified twice.');

        foreach (self::FINANCE_ROUTES as $name) {
            self::assertContains('ROLE_FINANCE', $routes[$name], sprintf('%s must require ROLE_FINANCE.', $name));
        }
        foreach (self::INVENTORY_ROUTES as $name) {
            self::assertContains('ROLE_INVENTORY', $routes[$name], sprintf('%s must require ROLE_INVENTORY.', $name));
        }
        foreach (self::ADMIN_ROUTES as $name) {
            self::assertContains('ROLE_ADMIN', $routes[$name], sprintf('%s must require ROLE_ADMIN.', $name));
        }
        foreach (self::DMS_ADMIN_ROUTES as $name) {
            self::assertContains('ROLE_DMS_ADMIN', $routes[$name], sprintf('%s must require ROLE_DMS_ADMIN.', $name));
        }
        foreach (self::USER_ROUTES as $name) {
            self::assertContains('ROLE_USER', $routes[$name], sprintf('%s must require at least ROLE_USER.', $name));
            self::assertNotContains('ROLE_ADMIN', $routes[$name], sprintf('%s is documented as open to all users.', $name));
            self::assertNotContains('ROLE_FINANCE', $routes[$name], sprintf('%s is documented as open to all users.', $name));
            self::assertNotContains('ROLE_INVENTORY', $routes[$name], sprintf('%s is documented as open to all users.', $name));
            self::assertNotContains('ROLE_DMS_ADMIN', $routes[$name], sprintf('%s is documented as open to all users.', $name));
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

    public function test_role_hierarchy_lets_administrators_act_as_finance_and_inventory_but_not_the_reverse(): void
    {
        $configured = Yaml::parseFile(dirname(__DIR__, 3).'/config/packages/security.yaml')['security']['role_hierarchy'];
        $hierarchy = new RoleHierarchy(array_map(static fn (string|array $roles): array => (array) $roles, $configured));

        self::assertContains('ROLE_FINANCE', $hierarchy->getReachableRoleNames(['ROLE_ADMIN']));
        self::assertContains('ROLE_INVENTORY', $hierarchy->getReachableRoleNames(['ROLE_ADMIN']));
        self::assertNotContains('ROLE_ADMIN', $hierarchy->getReachableRoleNames(['ROLE_FINANCE']));
        self::assertNotContains('ROLE_ADMIN', $hierarchy->getReachableRoleNames(['ROLE_INVENTORY']));
        self::assertContains('ROLE_USER', $hierarchy->getReachableRoleNames(['ROLE_FINANCE']));
        self::assertContains('ROLE_USER', $hierarchy->getReachableRoleNames(['ROLE_INVENTORY']));
        // Segregation of duties: whoever approves purchase orders does not also receive the goods by default.
        self::assertNotContains('ROLE_INVENTORY', $hierarchy->getReachableRoleNames(['ROLE_FINANCE']));
        self::assertNotContains('ROLE_FINANCE', $hierarchy->getReachableRoleNames(['ROLE_INVENTORY']));
        // Registry clerks get document rights only; administrators are clerks too.
        self::assertContains('ROLE_DMS_ADMIN', $hierarchy->getReachableRoleNames(['ROLE_ADMIN']));
        self::assertSame(['ROLE_DMS_ADMIN', 'ROLE_USER'], $hierarchy->getReachableRoleNames(['ROLE_DMS_ADMIN']));
    }

    public function test_every_role_a_route_requires_can_be_assigned_from_the_console(): void
    {
        $required = array_unique(array_merge(...array_values($this->declaredRoutes())));

        self::assertSame([], array_values(array_diff($required, [...SetUserRolesCommand::ASSIGNABLE_ROLES, 'ROLE_USER'])));
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
