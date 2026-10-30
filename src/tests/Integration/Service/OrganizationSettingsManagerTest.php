<?php

namespace App\Tests\Integration\Service;

use App\Dto\OrganizationSettingsInput;
use App\Service\OrganizationSettingsManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class OrganizationSettingsManagerTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private OrganizationSettingsManager $settingsManager;

    protected function setUp(): void
    {
        putenv('DATABASE_URL=sqlite:///:memory:');
        $_ENV['DATABASE_URL'] = 'sqlite:///:memory:';
        $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';

        self::bootKernel();
        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->settingsManager = $container->get(OrganizationSettingsManager::class);

        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        (new SchemaTool($this->entityManager))->createSchema($metadata);
    }

    protected function tearDown(): void
    {
        $this->entityManager->close();
        parent::tearDown();
    }

    public function test_country_settings_are_saved_and_previous_values_can_be_restored(): void
    {
        self::assertSame(['LV', 'LT', 'EE', 'DE', 'GB'], array_column($this->settingsManager->countries(), 'code'));

        $settings = $this->settingsManager->get();
        self::assertNull($settings->getCountryCode());
        self::assertSame(10, $settings->getRetentionYears());

        $this->settingsManager->update($settings, new OrganizationSettingsInput('LV', null, 10), 'admin@example.test');
        self::assertSame('LV', $settings->getCountryCode());
        self::assertSame('EUR', $settings->getDefaultCurrency());

        $this->settingsManager->update($settings, new OrganizationSettingsInput('GB', 'GBP', 15), 'admin@example.test');
        self::assertSame('GB', $settings->getCountryCode());
        self::assertSame('GBP', $settings->getDefaultCurrency());
        self::assertSame(15, $settings->getRetentionYears());
        self::assertCount(2, $this->settingsManager->history());

        $latestChange = $this->settingsManager->history()[0];
        $this->settingsManager->restore($settings, $latestChange, 'admin@example.test');

        self::assertSame('LV', $settings->getCountryCode());
        self::assertSame('EUR', $settings->getDefaultCurrency());
        self::assertSame(10, $settings->getRetentionYears());
        self::assertCount(3, $this->settingsManager->history());
        self::assertSame('admin@example.test', $settings->getUpdatedBy());
    }

    public function test_settings_reject_unsupported_countries_and_retention_periods(): void
    {
        $settings = $this->settingsManager->get();

        try {
            $this->settingsManager->update($settings, new OrganizationSettingsInput('US', 'USD', 10), 'admin@example.test');
            self::fail('Unsupported country should be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Select one of the supported operating countries.', $exception->getMessage());
        }

        try {
            $this->settingsManager->update($settings, new OrganizationSettingsInput('LV', 'EUR', 101), 'admin@example.test');
            self::fail('Out-of-range retention period should be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Retention period must be between 1 and 100 years.', $exception->getMessage());
        }
    }

    public function test_settings_reads_require_a_user_and_mutations_require_an_admin(): void
    {
        $controller = new \ReflectionClass(\App\Controller\OrganizationSettingsController::class);
        $classAccess = $controller->getAttributes(IsGranted::class)[0]->newInstance();
        $updateAccess = $controller->getMethod('update')->getAttributes(IsGranted::class)[0]->newInstance();
        $restoreAccess = $controller->getMethod('restore')->getAttributes(IsGranted::class)[0]->newInstance();

        self::assertSame('ROLE_USER', $classAccess->attribute);
        self::assertSame('ROLE_ADMIN', $updateAccess->attribute);
        self::assertSame('ROLE_ADMIN', $restoreAccess->attribute);
    }
}
