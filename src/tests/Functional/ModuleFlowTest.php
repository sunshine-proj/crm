<?php

namespace App\Tests\Functional;

use App\Entity\Notification;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/** Phase 9: administrators switch modules per installation; routes, postings, history and notifications follow. */
final class ModuleFlowTest extends ApiTestCase
{
    private function module(string $as, string $key): array
    {
        self::assertSame(200, $this->callAs($as, 'GET', '/api/settings/modules'), $this->lastBody);
        foreach ($this->json()['modules'] as $module) {
            if ($module['key'] === $key) {
                return $module;
            }
        }
        self::fail('Module '.$key.' missing');
    }

    private function set(string $key, array $body, int $expected = 200): array
    {
        self::assertSame($expected, $this->callAs('admin', 'PUT', '/api/settings/modules/'.$key, json_encode($body)), $this->lastBody);

        return $this->json();
    }

    public function test_every_module_is_on_by_default_and_only_administrators_change_them(): void
    {
        self::assertSame(200, $this->callAs('user', 'GET', '/api/settings/modules'));
        $modules = $this->json()['modules'];
        self::assertCount(13, $modules);
        self::assertSame([true], array_values(array_unique(array_column($modules, 'enabled'))));
        self::assertSame([true], array_values(array_unique(array_column($modules, 'available'))));
        self::assertSame(['ROLE_FINANCE', 'ROLE_INVENTORY', 'ROLE_DMS_ADMIN'], $this->json()['restrictable_roles']);
        self::assertSame(['weekly_capacity_hours' => 40, 'missing_time_reminders' => true], $this->module('user', 'time_tracking')['settings']);

        self::assertSame(403, $this->callAs('finance', 'PUT', '/api/settings/modules/invoices', '{"enabled":false}'));
        self::assertSame(403, $this->callAs('user', 'POST', '/api/settings/modules/presets/documents'));
        self::assertSame(403, $this->callAs('user', 'GET', '/api/settings/modules/history'));
        self::assertSame(404, $this->callAs('admin', 'PUT', '/api/settings/modules/payroll', '{"enabled":false}'));
    }

    public function test_a_disabled_module_answers_404_and_keeps_its_data(): void
    {
        $ids = $this->createClientAndProject();
        self::assertSame(201, $this->callAs('user', 'POST', '/api/invoices', json_encode(['project_id' => $ids['project'], 'invoice_date' => '2026-03-01', 'currency' => 'EUR', 'items' => [['description' => 'Work', 'amount' => '10.00']]])), $this->lastBody);
        $invoice = $this->json()['id'];

        $this->set('bank', ['enabled' => false]);
        $state = $this->set('invoices', ['enabled' => false]);
        self::assertFalse($state['enabled']);
        self::assertSame(404, $this->callAs('user', 'GET', '/api/invoices'));
        self::assertSame(['error' => 'The Invoices module is turned off for this organization.', 'module' => 'invoices'], $this->json());
        self::assertSame(404, $this->callAs('admin', 'GET', '/api/invoices/'.$invoice), 'administrators are blocked too while it is off');
        self::assertSame(404, $this->callAs('user', 'POST', '/api/sales-orders/1/invoice'), 'routes that also need invoices are blocked');
        self::assertSame(200, $this->callAs('user', 'GET', '/api/clients'), 'other modules stay available');
        self::assertSame(200, $this->callAs('user', 'GET', '/api/settings'), 'core stays available');
        self::assertSame(200, $this->callAs('user', 'GET', '/api/projects'));

        $this->set('invoices', ['enabled' => true]);
        self::assertSame(200, $this->callAs('user', 'GET', '/api/invoices/'.$invoice), 'data comes back when the module is on again');
    }

    public function test_dependencies_are_enforced_in_both_directions(): void
    {
        $refused = $this->set('projects', ['enabled' => false], 409);
        self::assertSame(['time_tracking', 'project_operations'], $refused['blocking']);
        self::assertStringContainsString('Turn off Time tracking, Project expenses first', $refused['error']);

        $this->set('time_tracking', ['enabled' => false]);
        $this->set('project_operations', ['enabled' => false]);
        $this->set('projects', ['enabled' => false]);
        self::assertSame(404, $this->callAs('user', 'GET', '/api/tasks'));
        self::assertSame(404, $this->callAs('user', 'POST', '/api/invoices', '{}'), 'manual invoices are project-linked');
        self::assertSame(200, $this->callAs('user', 'GET', '/api/projects/managers'), 'the shared user picker stays available');

        $refused = $this->set('time_tracking', ['enabled' => true], 409);
        self::assertSame(['projects'], $refused['blocking']);
        $this->set('projects', ['enabled' => true]);
        $this->set('time_tracking', ['enabled' => true]);
        self::assertSame(200, $this->callAs('user', 'GET', '/api/operations/time-report'));
    }

    public function test_a_module_can_be_restricted_to_roles(): void
    {
        $state = $this->set('projects', ['allowed_roles' => ['ROLE_INVENTORY', 'ROLE_FINANCE', 'ROLE_INVENTORY']]);
        self::assertSame(['ROLE_FINANCE', 'ROLE_INVENTORY'], $state['allowed_roles'], 'roles are de-duplicated and sorted');
        self::assertTrue($state['available'], 'administrators always see enabled modules');

        self::assertSame(403, $this->callAs('user', 'GET', '/api/projects'));
        self::assertSame('The Project management module is not available for your role.', $this->json()['error']);
        self::assertSame(200, $this->callAs('inventory', 'GET', '/api/projects'));
        self::assertSame(200, $this->callAs('buyer', 'GET', '/api/tasks'));
        self::assertSame(200, $this->callAs('admin', 'GET', '/api/projects'));
        self::assertFalse($this->module('user', 'projects')['available']);
        self::assertTrue($this->module('finance', 'projects')['available']);

        $this->set('projects', ['allowed_roles' => ['ROLE_ADMIN']], 400);
        $this->set('projects', ['allowed_roles' => [42]], 400);
        $this->set('projects', ['allowed_roles' => []]);
        self::assertSame(200, $this->callAs('user', 'GET', '/api/projects'), 'an empty list means everyone');
    }

    public function test_module_settings_are_validated_and_take_effect(): void
    {
        $this->set('time_tracking', ['settings' => ['weekly_capacity_hours' => 0]], 400);
        $this->set('time_tracking', ['settings' => ['weekly_capacity_hours' => '30']], 400);
        $this->set('time_tracking', ['settings' => ['missing_time_reminders' => 'no']], 400);
        $this->set('time_tracking', ['settings' => ['overtime' => 5]], 400);
        $state = $this->set('time_tracking', ['settings' => ['weekly_capacity_hours' => 30]]);
        self::assertSame(['weekly_capacity_hours' => 30, 'missing_time_reminders' => true], $state['settings'], 'other settings keep their value');

        self::assertSame(200, $this->callAs('user', 'GET', '/api/operations/time-report/workload'));
        self::assertSame(1800, $this->json()['capacity_minutes']);

        $project = $this->createClientAndProject(['budget_amount' => '100.00', 'budget_currency' => 'EUR'])['project'];
        $this->set('project_operations', ['settings' => ['budget_warning_percent' => 50]]);
        self::assertSame(200, $this->callAs('finance', 'GET', '/api/operations/projects/'.$project.'/profitability?currency=EUR'));
        self::assertSame(50, $this->json()['budget_warning_percent']);
    }

    public function test_changes_are_recorded_and_can_be_restored(): void
    {
        $this->set('news', ['enabled' => false]);
        $this->set('news', ['allowed_roles' => ['ROLE_FINANCE']]);
        self::assertSame(200, $this->callAs('admin', 'GET', '/api/settings/modules/history'));
        $history = $this->json();
        self::assertCount(2, $history);
        self::assertSame(['news', ['ROLE_FINANCE'], 'admin@example.test'], [$history[0]['module'], $history[0]['new']['allowed_roles'], $history[0]['actor']]);
        self::assertTrue($history[1]['previous']['enabled']);

        $this->set('news', ['enabled' => false]);
        self::assertSame(200, $this->callAs('admin', 'GET', '/api/settings/modules/history'));
        self::assertCount(2, $this->json(), 'a change that changes nothing is not recorded');

        self::assertSame(200, $this->callAs('admin', 'POST', '/api/settings/modules/history/'.$history[1]['id'].'/restore'), $this->lastBody);
        self::assertSame([true, []], [$this->json()['enabled'], $this->json()['allowed_roles']]);
        self::assertSame(404, $this->callAs('admin', 'POST', '/api/settings/modules/history/999/restore'));
    }

    public function test_presets_switch_a_whole_set_of_modules(): void
    {
        self::assertSame(200, $this->callAs('admin', 'POST', '/api/settings/modules/presets/documents'), $this->lastBody);
        $enabled = array_column(array_filter($this->json()['modules'], static fn (array $module): bool => $module['enabled']), 'key');
        self::assertSame(['news', 'organization_structure', 'document_management'], array_values($enabled));
        self::assertCount(10, $this->json()['changed']);
        self::assertSame(404, $this->callAs('user', 'GET', '/api/projects'));
        self::assertSame(404, $this->callAs('user', 'GET', '/api/clients'), 'clients are off in a document-management-only setup');
        self::assertSame(404, $this->callAs('user', 'GET', '/api/notes/client/1'), 'notes follow their record\'s module');
        self::assertSame(200, $this->callAs('user', 'GET', '/api/dashboard/summary'));
        self::assertSame(200, $this->callAs('user', 'GET', '/api/dms/types'));

        self::assertSame(200, $this->callAs('admin', 'POST', '/api/settings/modules/presets/full'));
        self::assertSame(200, $this->callAs('user', 'GET', '/api/projects'));
        self::assertSame(404, $this->callAs('admin', 'POST', '/api/settings/modules/presets/unknown'));
    }

    public function test_invoices_issue_without_ledger_postings_while_accounting_is_off(): void
    {
        $ids = $this->createClientAndProject();
        self::assertSame(201, $this->callAs('user', 'POST', '/api/invoices', json_encode(['project_id' => $ids['project'], 'invoice_date' => '2026-03-01', 'currency' => 'EUR', 'items' => [['description' => 'Work', 'amount' => '10.00']]])));
        $uri = '/api/invoices/'.$this->json()['id'];
        self::assertSame(409, $this->callAs('finance', 'POST', $uri.'/issue'), 'with accounting on, issuing needs a ledger');

        $this->set('bank', ['enabled' => false]);
        $this->set('accounting', ['enabled' => false]);
        self::assertSame(404, $this->callAs('finance', 'GET', '/api/finance/journal'));
        self::assertSame(200, $this->callAs('finance', 'GET', '/api/finance/currencies'), 'the currency list is shared');
        self::assertSame(200, $this->callAs('finance', 'POST', $uri.'/issue'), $this->lastBody);
        self::assertSame('issued', $this->json()['status']);
        self::assertSame(200, $this->callAs('finance', 'GET', $uri));
        self::assertSame('issued', $this->json()['status'], 'the issue is saved without a journal entry');
        self::assertSame(201, $this->callAs('finance', 'POST', $uri.'/payments', '{"amount":"10.00","paid_at":"2026-03-05","reference":"R1"}'), $this->lastBody);
    }

    public function test_history_and_notifications_of_hidden_modules_are_not_shown(): void
    {
        self::assertSame(201, $this->callAs('user', 'POST', '/api/deals', json_encode(['title' => 'Big', 'client_id' => $this->createClientAndProject()['client'], 'amount' => '100.00', 'currency' => 'EUR'])), $this->lastBody);
        $deal = $this->json()['id'];
        self::assertSame(200, $this->callAs('user', 'GET', '/api/history/deal/'.$deal));
        $this->set('sales_pipeline', ['enabled' => false]);
        self::assertSame(404, $this->callAs('user', 'GET', '/api/history/deal/'.$deal));
        self::assertSame(200, $this->callAs('user', 'GET', '/api/history/client/1'), 'record types of enabled modules stay visible');

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $user = $entityManager->find(User::class, $this->users['user']->getId());
        $entityManager->persist(new Notification($user, Notification::DUE_SOON, 'task', 1, null, 'Task "Design" is due today.', 'task:1:soon'));
        $entityManager->persist(new Notification($user, Notification::DUE_SOON, 'document', 1, null, 'Review of "Policy" is due today.', 'document:1:soon'));
        $entityManager->flush();
        self::assertSame(200, $this->callAs('user', 'GET', '/api/notifications'));
        self::assertSame(2, $this->json()['unread_count']);

        $this->set('projects', ['allowed_roles' => ['ROLE_FINANCE']]);
        self::assertSame(200, $this->callAs('user', 'GET', '/api/notifications'));
        self::assertSame(1, $this->json()['unread_count']);
        self::assertSame(['document'], array_column($this->json()['items'], 'subject_type'));
        self::assertSame(200, $this->callAs('finance', 'GET', '/api/settings/modules'), 'finance still reads module state');
    }

    public function test_clients_can_be_turned_off_once_nothing_needs_them(): void
    {
        $client = $this->createClientAndProject()['client'];
        $refused = $this->set('clients', ['enabled' => false], 409);
        self::assertSame(['sales_pipeline', 'projects', 'sales', 'invoices'], $refused['blocking']);

        self::assertSame(200, $this->callAs('admin', 'POST', '/api/settings/modules/presets/documents'));
        self::assertFalse($this->module('user', 'clients')['enabled']);
        self::assertSame(404, $this->callAs('admin', 'GET', '/api/clients/'.$client));
        self::assertSame(404, $this->callAs('user', 'GET', '/api/history/client/'.$client));
        self::assertSame(404, $this->callAs('user', 'POST', '/api/notes/project/1', '{"content":"x"}'));

        $this->set('projects', ['enabled' => true], 409);
        $this->set('clients', ['enabled' => true]);
        self::assertSame(200, $this->callAs('user', 'GET', '/api/clients/'.$client), 'client data comes back');
        self::assertSame(200, $this->callAs('user', 'GET', '/api/notes/client/'.$client));
    }
}
