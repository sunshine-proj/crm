<?php

namespace App\Service;

use App\Dto\OrganizationSettingsInput;
use App\Entity\OrganizationSettings;
use App\Entity\OrganizationSettingsChange;
use App\Repository\OrganizationSettingsChangeRepository;
use App\Repository\OrganizationSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;

final class OrganizationSettingsManager
{
    public function __construct(
        private readonly OrganizationSettingsRepository $settingsRepository,
        private readonly OrganizationSettingsChangeRepository $changeRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /** @return list<array{code: string, name: string, default_currency: string}> */
    public function countries(): array
    {
        return [
            ['code' => 'LV', 'name' => 'Latvia', 'default_currency' => 'EUR'],
            ['code' => 'LT', 'name' => 'Lithuania', 'default_currency' => 'EUR'],
            ['code' => 'EE', 'name' => 'Estonia', 'default_currency' => 'EUR'],
            ['code' => 'DE', 'name' => 'Germany', 'default_currency' => 'EUR'],
            ['code' => 'GB', 'name' => 'United Kingdom', 'default_currency' => 'GBP'],
        ];
    }

    public function get(): OrganizationSettings
    {
        $settings = $this->settingsRepository->find(1);
        if ($settings instanceof OrganizationSettings) {
            return $settings;
        }

        $settings = new OrganizationSettings();
        $this->entityManager->persist($settings);
        $this->entityManager->flush();

        return $settings;
    }

    /** @return list<OrganizationSettingsChange> */
    public function history(): array
    {
        return $this->changeRepository->findBy([], ['changedAt' => 'DESC', 'id' => 'DESC'], 50);
    }

    public function update(OrganizationSettings $settings, OrganizationSettingsInput $input, string $actor): OrganizationSettings
    {
        if ($input->country_code === null || $input->retention_years === null) {
            throw new \InvalidArgumentException('Country and retention period are required.');
        }

        $country = $this->findCountry($input->country_code);
        if ($country === null) {
            throw new \InvalidArgumentException('Select one of the supported operating countries.');
        }
        if ($input->retention_years < 1 || $input->retention_years > 100) {
            throw new \InvalidArgumentException('Retention period must be between 1 and 100 years.');
        }
        if (
            $input->default_currency !== null
            && !in_array($input->default_currency, OrganizationSettings::SUPPORTED_CURRENCIES, true)
        ) {
            throw new \InvalidArgumentException('Select a supported default currency.');
        }

        $currency = $input->default_currency ?? $country['default_currency'];
        $changed = $settings->getCountryCode() !== $input->country_code
            || $settings->getDefaultCurrency() !== $currency
            || $settings->getRetentionYears() !== $input->retention_years;
        if (!$changed) {
            return $settings;
        }

        $now = new \DateTimeImmutable();
        $this->entityManager->persist(new OrganizationSettingsChange(
            $settings->getCountryCode(),
            $settings->getDefaultCurrency(),
            $settings->getRetentionYears(),
            $input->country_code,
            $currency,
            $input->retention_years,
            $actor,
            $now,
        ));
        $settings->update($input->country_code, $currency, $input->retention_years, $actor, $now);
        $this->entityManager->flush();

        return $settings;
    }

    public function restore(
        OrganizationSettings $settings,
        OrganizationSettingsChange $change,
        string $actor,
    ): OrganizationSettings {
        $countryCode = $change->getPreviousCountryCode();
        if ($countryCode !== null && $this->findCountry($countryCode) === null) {
            throw new \InvalidArgumentException('The saved country is no longer supported.');
        }
        $currency = $change->getPreviousCurrency();
        if ($currency !== null && !in_array($currency, OrganizationSettings::SUPPORTED_CURRENCIES, true)) {
            throw new \InvalidArgumentException('The saved currency is no longer supported.');
        }

        $now = new \DateTimeImmutable();
        $this->entityManager->persist(new OrganizationSettingsChange(
            $settings->getCountryCode(),
            $settings->getDefaultCurrency(),
            $settings->getRetentionYears(),
            $countryCode,
            $currency,
            $change->getPreviousRetentionYears(),
            $actor,
            $now,
        ));
        $settings->update(
            $countryCode,
            $currency,
            $change->getPreviousRetentionYears(),
            $actor,
            $now,
        );
        $this->entityManager->flush();

        return $settings;
    }

    /** @return array{code: string, name: string, default_currency: string}|null */
    private function findCountry(string $code): ?array
    {
        foreach ($this->countries() as $country) {
            if ($country['code'] === $code) {
                return $country;
            }
        }

        return null;
    }
}
