<?php

namespace App\Service\Module;

use App\Entity\OrganizationModule;
use App\Entity\OrganizationModuleChange;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;

/**
 * Write side of the module configuration, shared by the administrator API and the console command. Enforces the
 * dependency rules (a module can be enabled only when what it needs is enabled, and disabled only when nothing
 * enabled needs it), validates role restrictions and settings, and records every change.
 */
final class ModuleConfigurator
{
    public function __construct(
        private readonly ModuleGate $gate,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire(service: 'monolog.logger.audit')] private readonly LoggerInterface $auditLogger,
    ) {
    }

    /**
     * @param list<string>|null $allowedRoles null keeps the current restriction
     * @param array<string, mixed>|null $settings null keeps the current settings; given keys are merged
     * @throws ModuleChangeRefused
     */
    public function configure(string $key, ?bool $enabled, ?array $allowedRoles, ?array $settings, string $actor): OrganizationModule
    {
        if (!ModuleCatalog::exists($key)) {
            throw new ModuleChangeRefused('Module not found.', Response::HTTP_NOT_FOUND);
        }
        $previous = $this->snapshot($key);
        $enabled ??= $previous['enabled'];
        if ($enabled && !$previous['enabled']) {
            $missing = array_values(array_filter(ModuleCatalog::MODULES[$key]['requires'], fn (string $required): bool => !$this->gate->isEnabled($required)));
            if ($missing !== []) {
                throw new ModuleChangeRefused(sprintf('Enable %s first.', $this->labels($missing)), Response::HTTP_CONFLICT, $missing);
            }
        }
        if (!$enabled && $previous['enabled']) {
            $blocking = array_values(array_filter(ModuleCatalog::dependents($key), fn (string $dependent): bool => $this->gate->isEnabled($dependent)));
            if ($blocking !== []) {
                throw new ModuleChangeRefused(sprintf('Turn off %s first; it needs %s.', $this->labels($blocking), ModuleCatalog::MODULES[$key]['label']), Response::HTTP_CONFLICT, $blocking);
            }
        }
        $roles = $allowedRoles === null ? $previous['allowed_roles'] : $this->validRoles($allowedRoles);
        $values = $previous['settings'];
        foreach ($settings ?? [] as $name => $value) {
            $values[$name] = $this->validSetting($key, (string) $name, $value);
        }

        $module = $this->gate->stored($key) ?? new OrganizationModule($key, $actor);
        $module->configure($enabled, $roles, $values, $actor);
        $new = ['enabled' => $enabled, 'allowed_roles' => $roles, 'settings' => $values];
        if ($new !== $previous) {
            $this->entityManager->persist($module);
            $this->entityManager->persist(new OrganizationModuleChange($key, $previous, $new, $actor));
            $this->entityManager->flush();
            $this->gate->reset();
            $this->auditLogger->info('Module configuration changed.', ['module' => $key, 'actor' => $actor, 'enabled' => $enabled, 'allowed_roles' => $roles]);
        }

        return $module;
    }

    /** Ready-made module sets an administrator can apply and then adjust; role restrictions and settings are kept. */
    public const PRESETS = [
        'full' => ['label' => 'Everything', 'modules' => ['clients', 'news', 'sales_pipeline', 'projects', 'time_tracking', 'project_operations', 'sales', 'inventory', 'invoices', 'accounting', 'bank', 'organization_structure', 'document_management']],
        'projects_invoicing' => ['label' => 'Projects and invoicing', 'modules' => ['clients', 'news', 'sales_pipeline', 'projects', 'time_tracking', 'project_operations', 'invoices']],
        'sales_inventory' => ['label' => 'Sales and inventory', 'modules' => ['clients', 'sales_pipeline', 'sales', 'inventory', 'invoices', 'accounting', 'bank']],
        'documents' => ['label' => 'Document management only', 'modules' => ['news', 'organization_structure', 'document_management']],
    ];

    /**
     * Turns exactly the preset's modules on and every other module off in one step.
     *
     * @return list<string> modules whose state changed
     * @throws ModuleChangeRefused
     */
    public function applyPreset(string $preset, string $actor): array
    {
        $modules = self::PRESETS[$preset]['modules'] ?? throw new ModuleChangeRefused('Preset not found.', Response::HTTP_NOT_FOUND);

        return $this->applyEnabledSet($modules, $actor);
    }

    /**
     * Sets which modules are on; the set must contain every dependency of its members.
     *
     * @param list<string> $enabledModules
     * @return list<string> modules whose state changed
     */
    public function applyEnabledSet(array $enabledModules, string $actor): array
    {
        foreach ($enabledModules as $key) {
            if (!ModuleCatalog::exists($key)) {
                throw new ModuleChangeRefused(sprintf('Unknown module "%s".', $key), Response::HTTP_BAD_REQUEST);
            }
            $missing = array_values(array_diff(ModuleCatalog::MODULES[$key]['requires'], $enabledModules));
            if ($missing !== []) {
                throw new ModuleChangeRefused(sprintf('%s needs %s.', ModuleCatalog::MODULES[$key]['label'], $this->labels($missing)), Response::HTTP_CONFLICT, $missing);
            }
        }
        $changed = [];
        foreach (ModuleCatalog::keys() as $key) {
            $previous = $this->snapshot($key);
            $enabled = in_array($key, $enabledModules, true);
            if ($previous['enabled'] === $enabled) {
                continue;
            }
            $module = $this->gate->stored($key) ?? new OrganizationModule($key, $actor);
            $module->configure($enabled, $previous['allowed_roles'], $previous['settings'], $actor);
            $this->entityManager->persist($module);
            $this->entityManager->persist(new OrganizationModuleChange($key, $previous, ['enabled' => $enabled] + $previous, $actor));
            $changed[] = $key;
        }
        if ($changed !== []) {
            $this->entityManager->flush();
            $this->gate->reset();
            $this->auditLogger->info('Module set applied.', ['actor' => $actor, 'enabled' => $enabledModules, 'changed' => $changed]);
        }

        return $changed;
    }

    /** @return array{enabled: bool, allowed_roles: list<string>, settings: array<string, int|bool>} */
    public function snapshot(string $key): array
    {
        return ['enabled' => $this->gate->isEnabled($key), 'allowed_roles' => $this->gate->allowedRoles($key), 'settings' => $this->gate->settings($key)];
    }

    /**
     * @param list<mixed> $roles
     * @return list<string>
     */
    private function validRoles(array $roles): array
    {
        foreach ($roles as $role) {
            if (!is_string($role) || !in_array($role, ModuleCatalog::RESTRICTABLE_ROLES, true)) {
                throw new ModuleChangeRefused(sprintf('Allowed roles must be chosen from %s.', implode(', ', ModuleCatalog::RESTRICTABLE_ROLES)), Response::HTTP_BAD_REQUEST);
            }
        }
        $roles = array_values(array_unique($roles));
        sort($roles);

        return $roles;
    }

    private function validSetting(string $key, string $name, mixed $value): int|bool
    {
        $definition = ModuleCatalog::SETTINGS[$key][$name] ?? throw new ModuleChangeRefused(sprintf('Unknown setting "%s".', $name), Response::HTTP_BAD_REQUEST);
        if ($definition['type'] === 'bool') {
            if (!is_bool($value)) {
                throw new ModuleChangeRefused(sprintf('%s must be true or false.', $name), Response::HTTP_BAD_REQUEST);
            }

            return $value;
        }
        if (!is_int($value) || $value < $definition['min'] || $value > $definition['max']) {
            throw new ModuleChangeRefused(sprintf('%s must be a whole number from %d to %d.', $name, $definition['min'], $definition['max']), Response::HTTP_BAD_REQUEST);
        }

        return $value;
    }

    /** @param list<string> $keys */
    private function labels(array $keys): string
    {
        return implode(', ', array_map(static fn (string $key): string => ModuleCatalog::MODULES[$key]['label'], $keys));
    }
}
