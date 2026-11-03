<?php

namespace App\Service;

use App\Entity\OrganizationSettings;
use App\Repository\JurisdictionValidationRepository;
use App\Repository\OrganizationSettingsRepository;

/**
 * Decides whether jurisdiction-specific behaviour may run for the organisation's country: tax on invoices and
 * gapless numbering switch on only while an adviser's sign-off for that country and scope is on record.
 */
final class JurisdictionGate
{
    public function __construct(
        private readonly OrganizationSettingsRepository $settingsRepository,
        private readonly JurisdictionValidationRepository $validationRepository,
        private readonly JurisdictionCatalog $catalog,
    ) {
    }

    public function settings(): ?OrganizationSettings
    {
        $settings = $this->settingsRepository->find(1);

        return $settings instanceof OrganizationSettings ? $settings : null;
    }

    public function country(): ?string
    {
        $country = $this->settings()?->getCountryCode();

        return $country !== null && $this->catalog->supports($country) ? $country : null;
    }

    /** @return array{country: string, rounding: string, tax_code: string, standard_rate_bp: int}|null null while tax is not validated */
    public function taxProfile(): ?array
    {
        $country = $this->country();
        if ($country === null) {
            return null;
        }
        $validation = $this->validationRepository->findActive($country, 'tax');
        $profile = $this->catalog->profile($country);
        if ($validation === null || $profile === null) {
            return null;
        }

        return ['country' => $country, 'rounding' => (string) $validation->getRounding(), 'tax_code' => $profile['tax_code'], 'standard_rate_bp' => $profile['standard_rate_bp']];
    }

    public function numberingActive(): bool
    {
        $country = $this->country();

        return $country !== null && $this->validationRepository->findActive($country, 'numbering') !== null;
    }

    /**
     * What the issuing organisation still has to provide before invoices can be issued in a validated jurisdiction.
     *
     * @return list<string>
     */
    public function legalProfileProblems(): array
    {
        $settings = $this->settings();
        $problems = [];
        if ($settings === null || trim((string) $settings->getLegalName()) === '') {
            $problems[] = 'the organisation legal name';
        }
        if ($settings === null || trim((string) $settings->getLegalAddress()) === '') {
            $problems[] = 'the organisation address';
        }
        if ($this->taxProfile() !== null && ($settings === null || trim((string) $settings->getVatNumber()) === '')) {
            $problems[] = 'the organisation VAT number';
        }

        return $problems;
    }
}
