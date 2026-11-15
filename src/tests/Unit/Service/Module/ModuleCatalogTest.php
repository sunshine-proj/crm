<?php

namespace App\Tests\Unit\Service\Module;

use App\Service\Module\ModuleCatalog;
use App\Service\Module\ModuleConfigurator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Attribute\Route;

/** Pins the module catalog: every API route belongs to core or a module, and dependencies and presets are consistent. */
final class ModuleCatalogTest extends TestCase
{
    public function test_every_api_route_belongs_to_core_or_a_module(): void
    {
        $unassigned = [];
        foreach ($this->routeNames() as $name) {
            if (ModuleCatalog::modulesForRoute($name) === null) {
                $unassigned[] = $name;
            }
        }
        self::assertSame([], $unassigned, 'Assign the route to a module (route_prefixes) or to CORE_ROUTE_PREFIXES in ModuleCatalog.');
    }

    public function test_every_module_owns_at_least_one_route_and_every_prefix_matches_a_route(): void
    {
        $names = $this->routeNames();
        foreach (ModuleCatalog::MODULES as $key => $module) {
            foreach ($module['route_prefixes'] as $prefix) {
                self::assertNotSame([], array_filter($names, static fn (string $name): bool => str_starts_with($name, $prefix) && ModuleCatalog::modulesForRoute($name)[0] === $key), sprintf('%s prefix %s matches no route', $key, $prefix));
            }
        }
        foreach (array_keys(ModuleCatalog::ROUTE_REQUIREMENTS) as $route) {
            self::assertContains($route, $names);
        }
    }

    public function test_route_assignment_uses_the_longest_prefix_and_extra_requirements(): void
    {
        self::assertSame([], ModuleCatalog::modulesForRoute('api_login'));
        self::assertSame([], ModuleCatalog::modulesForRoute('api_projects_managers'), 'the user picker is shared');
        self::assertSame([], ModuleCatalog::modulesForRoute('api_finance_currencies'));
        self::assertSame(['projects'], ModuleCatalog::modulesForRoute('api_projects_list'));
        self::assertSame(['accounting'], ModuleCatalog::modulesForRoute('api_finance_journal_list'));
        self::assertSame(['time_tracking'], ModuleCatalog::modulesForRoute('api_operations_time_report_export'));
        self::assertSame(['project_operations'], ModuleCatalog::modulesForRoute('api_operations_expenses_list'));
        self::assertSame(['invoices', 'time_tracking'], ModuleCatalog::modulesForRoute('api_invoices_from_approved_time'));
        self::assertSame(['sales', 'invoices'], ModuleCatalog::modulesForRoute('api_sales_orders_invoice'));
        self::assertNull(ModuleCatalog::modulesForRoute('_profiler'));
    }

    public function test_dependencies_exist_and_form_no_cycle(): void
    {
        foreach (ModuleCatalog::MODULES as $key => $module) {
            foreach ($module['requires'] as $required) {
                self::assertTrue(ModuleCatalog::exists($required), sprintf('%s requires unknown %s', $key, $required));
            }
            $seen = [];
            $pending = $module['requires'];
            while ($pending !== []) {
                $current = array_pop($pending);
                self::assertNotSame($key, $current, sprintf('%s depends on itself', $key));
                if (!isset($seen[$current])) {
                    $seen[$current] = true;
                    array_push($pending, ...ModuleCatalog::MODULES[$current]['requires']);
                }
            }
        }
        self::assertSame(['time_tracking', 'project_operations'], ModuleCatalog::dependents('projects'));
    }

    public function test_presets_contain_their_dependencies_and_settings_defaults_are_valid(): void
    {
        foreach (ModuleConfigurator::PRESETS as $name => $preset) {
            foreach ($preset['modules'] as $key) {
                self::assertTrue(ModuleCatalog::exists($key), $name);
                self::assertSame([], array_diff(ModuleCatalog::MODULES[$key]['requires'], $preset['modules']), sprintf('preset %s misses a dependency of %s', $name, $key));
            }
        }
        self::assertSame(ModuleCatalog::keys(), ModuleConfigurator::PRESETS['full']['modules']);
        foreach (ModuleCatalog::SETTINGS as $key => $settings) {
            self::assertTrue(ModuleCatalog::exists($key));
            foreach ($settings as $definition) {
                if ($definition['type'] === 'int') {
                    self::assertGreaterThanOrEqual($definition['min'], $definition['default']);
                    self::assertLessThanOrEqual($definition['max'], $definition['default']);
                } else {
                    self::assertIsBool($definition['default']);
                }
            }
        }
    }

    /** @return list<string> */
    private function routeNames(): array
    {
        $names = [];
        foreach (glob(dirname(__DIR__, 4).'/src/Controller/*Controller.php') ?: [] as $file) {
            $class = new \ReflectionClass('App\\Controller\\'.basename($file, '.php'));
            $prefix = ($class->getAttributes(Route::class)[0] ?? null)?->newInstance()->name ?? '';
            foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                $route = $method->getAttributes(Route::class)[0] ?? null;
                if ($route !== null) {
                    $names[] = $prefix.$route->newInstance()->name;
                }
            }
        }

        return $names;
    }
}
