<?php

namespace App\Controller;

use App\Dto\OrganizationSettingsInput;
use App\Entity\OrganizationSettings;
use App\Entity\OrganizationSettingsChange;
use App\Repository\OrganizationSettingsChangeRepository;
use App\Service\FinanceCatalog;
use App\Service\OrganizationSettingsManager;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/settings', name: 'api_organization_settings_')]
#[IsGranted('ROLE_USER')]
final class OrganizationSettingsController extends AbstractController
{
    public function __construct(
        private readonly OrganizationSettingsManager $settingsManager,
        private readonly OrganizationSettingsChangeRepository $changeRepository,
        private readonly FinanceCatalog $financeCatalog,
        #[Autowire(service: 'monolog.logger.audit')]
        private readonly LoggerInterface $auditLogger,
    ) {
    }

    #[Route('', name: 'show', methods: ['GET'])]
    public function show(): JsonResponse
    {
        return $this->json($this->serializeSettings($this->settingsManager->get()));
    }

    #[Route('', name: 'update', methods: ['PUT'])]
    #[IsGranted('ROLE_ADMIN')]
    public function update(#[MapRequestPayload] OrganizationSettingsInput $input): JsonResponse
    {
        $settings = $this->settingsManager->get();
        $this->settingsManager->update($settings, $input, $this->getActor());
        $this->auditLogger->info('Organization settings updated.', [
            'action' => 'organization.settings.update',
            'actor' => $this->getActor(),
            'country_code' => $settings->getCountryCode(),
            'retention_years' => $settings->getRetentionYears(),
        ]);

        return $this->json($this->serializeSettings($settings));
    }

    #[Route('/history/{id<\d+>}/restore', name: 'restore', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function restore(int $id): JsonResponse
    {
        $change = $this->changeRepository->find($id);
        if (!$change instanceof OrganizationSettingsChange) {
            return $this->json(['error' => 'Settings history entry not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        $settings = $this->settingsManager->restore($this->settingsManager->get(), $change, $this->getActor());
        $this->auditLogger->warning('Organization settings restored from history.', [
            'action' => 'organization.settings.restore',
            'actor' => $this->getActor(),
            'history_id' => $id,
            'country_code' => $settings->getCountryCode(),
        ]);

        return $this->json($this->serializeSettings($settings));
    }

    /** @return array<string, mixed> */
    private function serializeSettings(OrganizationSettings $settings): array
    {
        $canManage = $this->isGranted('ROLE_ADMIN');
        $country = null;
        foreach ($this->settingsManager->countries() as $availableCountry) {
            if ($availableCountry['code'] === $settings->getCountryCode()) {
                $country = $availableCountry;
                break;
            }
        }

        return [
            'country_code' => $settings->getCountryCode(),
            'country_name' => $country['name'] ?? null,
            'default_currency' => $settings->getDefaultCurrency(),
            'retention_years' => $settings->getRetentionYears(),
            'updated_at' => $settings->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
            'updated_by' => $settings->getUpdatedBy(),
            'countries' => $this->settingsManager->countries(),
            'currencies' => array_map(
                static fn (array $currency): array => ['code' => $currency['code'], 'name' => $currency['name']],
                $this->financeCatalog->currencies(),
            ),
            'deployment_model' => 'single_organization_per_installation',
            'finance_scope' => 'operational_only',
            'jurisdiction_profile_status' => $country === null ? 'not_selected' : 'unverified',
            'tax_rules_status' => 'not_configured',
            'can_manage' => $canManage,
            'history' => $canManage ? array_map($this->serializeChange(...), $this->settingsManager->history()) : [],
        ];
    }

    /** @return array<string, mixed> */
    private function serializeChange(OrganizationSettingsChange $change): array
    {
        return [
            'id' => $change->getId(),
            'previous_country_code' => $change->getPreviousCountryCode(),
            'previous_currency' => $change->getPreviousCurrency(),
            'previous_retention_years' => $change->getPreviousRetentionYears(),
            'new_country_code' => $change->getNewCountryCode(),
            'new_currency' => $change->getNewCurrency(),
            'new_retention_years' => $change->getNewRetentionYears(),
            'actor' => $change->getActor(),
            'changed_at' => $change->getChangedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    private function getActor(): string
    {
        $user = $this->getUser();

        return $user?->getUserIdentifier() ?? 'unknown';
    }
}
