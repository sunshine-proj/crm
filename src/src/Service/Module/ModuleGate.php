<?php

namespace App\Service\Module;

use App\Entity\OrganizationModule;
use App\Entity\User;
use App\Repository\OrganizationModuleRepository;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Read side of the module configuration: whether a module is on, whether a given user may see it, and its settings.
 * Visibility: an enabled module without role restrictions is visible to every signed-in user; a restricted module
 * only to users holding one of its roles (through the role hierarchy). Administrators see every enabled module.
 * The configuration is cached per request and reset between requests and scheduler runs.
 */
final class ModuleGate implements ResetInterface
{
    /** @var array<string, OrganizationModule>|null */
    private ?array $stored = null;

    public function __construct(
        private readonly OrganizationModuleRepository $repository,
        private readonly RoleHierarchyInterface $roleHierarchy,
    ) {
    }

    public function isEnabled(string $key): bool
    {
        if (!ModuleCatalog::exists($key)) {
            throw new \InvalidArgumentException(sprintf('Unknown module "%s".', $key));
        }

        return ($this->load()[$key] ?? null)?->isEnabled() ?? true;
    }

    /** @return list<string> */
    public function allowedRoles(string $key): array
    {
        return ($this->load()[$key] ?? null)?->getAllowedRoles() ?? [];
    }

    /** Enabled and visible to the user (or to anyone, when no user is given, as for unrestricted modules). */
    public function isAvailableTo(string $key, ?User $user): bool
    {
        if (!$this->isEnabled($key)) {
            return false;
        }
        $allowed = $this->allowedRoles($key);
        if ($allowed === []) {
            return true;
        }
        if ($user === null) {
            return false;
        }
        $roles = $this->roleHierarchy->getReachableRoleNames($user->getRoles());

        return in_array('ROLE_ADMIN', $roles, true) || array_intersect($allowed, $roles) !== [];
    }

    public function setting(string $key, string $name): int|bool
    {
        $definition = ModuleCatalog::SETTINGS[$key][$name] ?? throw new \InvalidArgumentException(sprintf('Unknown setting "%s.%s".', $key, $name));

        return ($this->load()[$key] ?? null)?->getSettings()[$name] ?? $definition['default'];
    }

    /** @return array<string, int|bool> every setting of the module, defaults filled in */
    public function settings(string $key): array
    {
        $values = [];
        foreach (array_keys(ModuleCatalog::SETTINGS[$key] ?? []) as $name) {
            $values[$name] = $this->setting($key, $name);
        }

        return $values;
    }

    /** Stored row of the module, or null when it still runs on defaults. */
    public function stored(string $key): ?OrganizationModule
    {
        return $this->load()[$key] ?? null;
    }

    public function reset(): void
    {
        $this->stored = null;
    }

    /** @return array<string, OrganizationModule> */
    private function load(): array
    {
        return $this->stored ??= $this->repository->allByKey();
    }
}
