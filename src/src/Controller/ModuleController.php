<?php

namespace App\Controller;

use App\Dto\ModuleUpdateInput;
use App\Entity\OrganizationModuleChange;
use App\Entity\User;
use App\Repository\OrganizationModuleChangeRepository;
use App\Service\Module\ModuleCatalog;
use App\Service\Module\ModuleChangeRefused;
use App\Service\Module\ModuleConfigurator;
use App\Service\Module\ModuleGate;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Module configuration: every user reads which modules they can use; administrators switch and configure them. */
#[Route('/api/settings/modules', name: 'api_modules_')]
#[IsGranted('ROLE_USER')]
final class ModuleController extends AbstractController
{
    public function __construct(
        private readonly ModuleGate $gate,
        private readonly ModuleConfigurator $configurator,
        private readonly OrganizationModuleChangeRepository $changeRepository,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return $this->json($this->overview());
    }

    /** @return array<string, mixed> */
    private function overview(): array
    {
        $user = $this->getUser();

        return [
            'modules' => array_map(fn (string $key): array => $this->serialize($key, $user instanceof User ? $user : null), ModuleCatalog::keys()),
            'restrictable_roles' => ModuleCatalog::RESTRICTABLE_ROLES,
            'presets' => array_map(static fn (string $key, array $preset): array => ['key' => $key, 'label' => $preset['label'], 'modules' => $preset['modules']], array_keys(ModuleConfigurator::PRESETS), array_values(ModuleConfigurator::PRESETS)),
        ];
    }

    #[Route('/{key<[a-z_]+>}', name: 'update', methods: ['PUT', 'PATCH'])]
    #[IsGranted('ROLE_ADMIN')]
    public function update(string $key, #[MapRequestPayload] ModuleUpdateInput $input): JsonResponse
    {
        try {
            $this->configurator->configure($key, $input->enabled, $input->allowed_roles, $input->settings, $this->actor());
        } catch (ModuleChangeRefused $refused) {
            return $this->refusal($refused);
        }
        $user = $this->getUser();

        return $this->json($this->serialize($key, $user instanceof User ? $user : null));
    }

    #[Route('/presets/{preset<[a-z_]+>}', name: 'preset', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function applyPreset(string $preset): JsonResponse
    {
        try {
            $changed = $this->configurator->applyPreset($preset, $this->actor());
        } catch (ModuleChangeRefused $refused) {
            return $this->refusal($refused);
        }

        return $this->json(['changed' => $changed] + $this->overview());
    }

    #[Route('/history', name: 'history', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function history(): JsonResponse
    {
        return $this->json(array_map(static fn (OrganizationModuleChange $change): array => [
            'id' => $change->getId(),
            'module' => $change->getModuleKey(),
            'module_label' => ModuleCatalog::MODULES[$change->getModuleKey()]['label'] ?? $change->getModuleKey(),
            'previous' => $change->getPrevious(),
            'new' => $change->getNew(),
            'actor' => $change->getActor(),
            'changed_at' => $change->getChangedAt()->format(\DateTimeInterface::ATOM),
        ], $this->changeRepository->recent()));
    }

    /** Puts a module back into the state it had before the given change (dependency rules still apply). */
    #[Route('/history/{id<\d+>}/restore', name: 'restore', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function restore(int $id): JsonResponse
    {
        $change = $this->changeRepository->find($id);
        if (!$change instanceof OrganizationModuleChange || !ModuleCatalog::exists($change->getModuleKey())) {
            return $this->json(['error' => 'Module change not found.'], JsonResponse::HTTP_NOT_FOUND);
        }
        $previous = $change->getPrevious();
        try {
            $this->configurator->configure($change->getModuleKey(), $previous['enabled'], $previous['allowed_roles'], $previous['settings'], $this->actor());
        } catch (ModuleChangeRefused $refused) {
            return $this->refusal($refused);
        }
        $user = $this->getUser();

        return $this->json($this->serialize($change->getModuleKey(), $user instanceof User ? $user : null));
    }

    /** @return array<string, mixed> */
    private function serialize(string $key, ?User $user): array
    {
        $module = ModuleCatalog::MODULES[$key];
        $stored = $this->gate->stored($key);

        return [
            'key' => $key,
            'label' => $module['label'],
            'description' => $module['description'],
            'requires' => $module['requires'],
            'required_by' => ModuleCatalog::dependents($key),
            'enabled' => $this->gate->isEnabled($key),
            'available' => $this->gate->isAvailableTo($key, $user),
            'allowed_roles' => $this->gate->allowedRoles($key),
            'settings' => (object) $this->gate->settings($key),
            'settings_schema' => (object) (ModuleCatalog::SETTINGS[$key] ?? []),
            'updated_at' => $stored?->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            'updated_by' => $stored?->getUpdatedBy(),
        ];
    }

    private function refusal(ModuleChangeRefused $refused): JsonResponse
    {
        return $this->json(['error' => $refused->getMessage(), 'blocking' => $refused->blocking()], $refused->status());
    }

    private function actor(): string
    {
        return $this->getUser()?->getUserIdentifier() ?? 'unknown';
    }
}
