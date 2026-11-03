<?php

namespace App\Service;

use App\Entity\OrganizationSettings;
use App\Repository\OrganizationSettingsRepository;

/**
 * Decides whether a purchase order needs approval before goods can be received.
 *
 * With no threshold configured nothing needs approval (the original behaviour). With a threshold, orders in the
 * organisation's default currency at or above it need approval; orders in any other currency always do, because
 * amounts in different currencies are never compared.
 */
final class PurchaseApprovalPolicy
{
    public function __construct(private readonly OrganizationSettingsRepository $settingsRepository)
    {
    }

    public function thresholdMinor(): ?int
    {
        $settings = $this->settingsRepository->find(1);

        return $settings instanceof OrganizationSettings ? $settings->getPurchaseApprovalThresholdMinor() : null;
    }

    public function requiresApproval(string $currency, int $totalMinor): bool
    {
        $settings = $this->settingsRepository->find(1);
        if (!$settings instanceof OrganizationSettings || $settings->getPurchaseApprovalThresholdMinor() === null) {
            return false;
        }

        return $settings->getDefaultCurrency() !== $currency || $totalMinor >= $settings->getPurchaseApprovalThresholdMinor();
    }
}
