<?php

namespace App\Controller;

use App\Dto\JurisdictionValidationInput;
use App\Dto\LegalProfileInput;
use App\Dto\RevocationInput;
use App\Entity\JurisdictionValidation;
use App\Entity\OrganizationSettings;
use App\Entity\TaxCode;
use App\Entity\User;
use App\Repository\JurisdictionValidationRepository;
use App\Repository\OrganizationSettingsRepository;
use App\Repository\TaxCodeRepository;
use App\Service\InvoiceMoney;
use App\Service\JurisdictionCatalog;
use App\Service\JurisdictionGate;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Country rules for tax and invoice numbering. Reference data comes from the catalog; behaviour is switched on only by
 * a recorded adviser sign-off, which the application stores but cannot verify.
 */
#[Route('/api/jurisdiction', name: 'api_jurisdiction_')]
#[IsGranted('ROLE_FINANCE')]
final class JurisdictionController extends AbstractController
{
    public function __construct(
        private readonly JurisdictionCatalog $catalog,
        private readonly JurisdictionGate $gate,
        private readonly JurisdictionValidationRepository $validationRepository,
        private readonly OrganizationSettingsRepository $settingsRepository,
        private readonly TaxCodeRepository $taxCodeRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly InvoiceMoney $money,
        #[Autowire(service: 'monolog.logger.audit')] private readonly LoggerInterface $auditLogger,
    ) {
    }

    #[Route('', name: 'overview', methods: ['GET'])]
    public function overview(): JsonResponse
    {
        $countries = [];
        foreach ($this->catalog->codes() as $code) {
            $profile = (array) $this->catalog->profile($code);
            $countries[] = [
                'code' => $code, 'name' => $profile['name'], 'tax_name' => $profile['tax_name'], 'authority' => $profile['authority'],
                'seed_standard_rate' => $this->money->formatMinor($profile['standard_rate_bp']), 'tax_code' => $profile['tax_code'],
                'tax_code_exists' => $this->taxCodeRepository->findOneBy(['code' => $profile['tax_code']]) !== null,
                'tax_validation' => $this->serializeValidation($this->validationRepository->findActive($code, 'tax')),
                'numbering_validation' => $this->serializeValidation($this->validationRepository->findActive($code, 'numbering')),
            ];
        }
        $taxProfile = $this->gate->taxProfile();

        return $this->json([
            'organization_country' => $this->gate->country(),
            'tax_active' => $taxProfile !== null,
            'tax_rounding' => $taxProfile['rounding'] ?? null,
            'numbering_active' => $this->gate->numberingActive(),
            'legal_profile_missing' => $this->gate->legalProfileProblems(),
            'countries' => $countries,
            'invoice_content_checklist' => JurisdictionCatalog::INVOICE_CONTENT_CHECKLIST,
            'basis' => 'Rates and authorities are seed reference data, not advice. Tax on invoices and gapless numbering stay off for the organisation country until a named adviser\'s sign-off is recorded for that scope. The application records the sign-off; it cannot verify it, and it does not make any document legally compliant.',
        ]);
    }

    #[Route('/validations', name: 'validations', methods: ['GET'])]
    public function validations(): JsonResponse
    {
        return $this->json(array_map($this->serializeValidation(...), $this->validationRepository->history()));
    }

    #[Route('/validations', name: 'validation_create', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function recordValidation(#[MapRequestPayload] JurisdictionValidationInput $input): JsonResponse
    {
        if ($input->country_code === null || $input->scope === null || $input->validated_on === null) {
            return $this->json(['error' => 'Country, scope, adviser, reference and validation date are required.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $input->validated_on);
        if ($date === false || $date > new \DateTimeImmutable('today')) {
            return $this->json(['error' => 'The validation date must be a past or present calendar date.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        if ($this->validationRepository->findActive($input->country_code, $input->scope) !== null) {
            return $this->json(['error' => 'An active validation already exists for this country and scope; revoke it first.'], JsonResponse::HTTP_CONFLICT);
        }
        try {
            $validation = new JurisdictionValidation($input->country_code, $input->scope, (string) $input->adviser_name, $input->adviser_firm, (string) $input->reference, $date, $input->rounding, $input->notes, $this->actor());
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['error' => $exception->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
        }
        $this->entityManager->persist($validation);
        $this->entityManager->flush();
        $this->auditLogger->warning('Jurisdiction validation recorded.', ['validation_id' => $validation->getId(), 'country' => $input->country_code, 'scope' => $input->scope, 'adviser' => $validation->getAdviserName(), 'actor' => $this->actor()]);

        return $this->json($this->serializeValidation($validation), JsonResponse::HTTP_CREATED);
    }

    #[Route('/validations/{id<\d+>}/revoke', name: 'validation_revoke', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function revoke(int $id, #[MapRequestPayload] RevocationInput $input): JsonResponse
    {
        $validation = $this->validationRepository->find($id);
        if (!$validation instanceof JurisdictionValidation) {
            return $this->json(['error' => 'Validation not found.'], JsonResponse::HTTP_NOT_FOUND);
        }
        try {
            $validation->revoke($this->actor(), (string) $input->reason);
        } catch (\DomainException $exception) {
            return $this->json(['error' => $exception->getMessage()], JsonResponse::HTTP_CONFLICT);
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['error' => $exception->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
        }
        $this->entityManager->flush();
        $this->auditLogger->warning('Jurisdiction validation revoked.', ['validation_id' => $id, 'actor' => $this->actor()]);

        return $this->json($this->serializeValidation($validation));
    }

    /** Creates the country's standard tax code at the seed rate. The adviser may change rates afterwards by adding codes. */
    #[Route('/standard-tax-code', name: 'standard_tax_code', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function createStandardTaxCode(): JsonResponse
    {
        $country = $this->gate->country();
        $profile = $country !== null ? $this->catalog->profile($country) : null;
        if ($profile === null) {
            return $this->json(['error' => 'Select a supported organisation country in Settings first.'], JsonResponse::HTTP_CONFLICT);
        }
        if ($this->taxCodeRepository->findOneBy(['code' => $profile['tax_code']]) !== null) {
            return $this->json(['error' => 'The standard tax code already exists.'], JsonResponse::HTTP_CONFLICT);
        }
        $taxCode = new TaxCode($profile['tax_code'], $profile['name'].' standard rate (seed value, to be confirmed)', $profile['standard_rate_bp']);
        $this->entityManager->persist($taxCode);
        $this->entityManager->flush();

        return $this->json(['code' => $taxCode->getCode(), 'rate_percent' => $this->money->formatMinor($taxCode->getRateBasisPoints())], JsonResponse::HTTP_CREATED);
    }

    #[Route('/legal-profile', name: 'legal_profile', methods: ['GET'])]
    public function legalProfile(): JsonResponse
    {
        return $this->json($this->serializeLegalProfile($this->gate->settings()));
    }

    #[Route('/legal-profile', name: 'legal_profile_update', methods: ['PUT'])]
    #[IsGranted('ROLE_ADMIN')]
    public function updateLegalProfile(#[MapRequestPayload] LegalProfileInput $input): JsonResponse
    {
        $clean = static fn (?string $value): ?string => $value === null || trim($value) === '' ? null : trim($value);
        $vat = $clean($input->vat_number);
        $email = $clean($input->email);
        if ($vat !== null && preg_match('/^[A-Za-z0-9][A-Za-z0-9 .-]{2,31}$/D', $vat) !== 1) {
            return $this->json(['error' => 'VAT number may contain only letters, digits, spaces, dots, and hyphens (3-32 characters).'], JsonResponse::HTTP_BAD_REQUEST);
        }
        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->json(['error' => 'Contact e-mail must be a valid address.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        $settings = $this->settingsRepository->find(1);
        if (!$settings instanceof OrganizationSettings) {
            $settings = new OrganizationSettings();
            $this->entityManager->persist($settings);
        }
        $settings->setLegalProfile($clean($input->legal_name), $clean($input->registration_number), $vat !== null ? strtoupper($vat) : null, $clean($input->address), $email, $clean($input->payment_instructions));
        $this->entityManager->flush();
        $this->auditLogger->info('Organisation legal profile updated.', ['actor' => $this->actor()]);

        return $this->json($this->serializeLegalProfile($settings));
    }

    private function actor(): string
    {
        $user = $this->getUser();

        return $user instanceof User ? $user->getUserIdentifier() : 'anonymous';
    }

    /** @return array<string, mixed>|null */
    private function serializeValidation(?JurisdictionValidation $validation): ?array
    {
        if ($validation === null) {
            return null;
        }

        return [
            'id' => $validation->getId(), 'country_code' => $validation->getCountryCode(), 'scope' => $validation->getScope(),
            'adviser_name' => $validation->getAdviserName(), 'adviser_firm' => $validation->getAdviserFirm(), 'reference' => $validation->getReference(),
            'validated_on' => $validation->getValidatedOn()->format('Y-m-d'), 'rounding' => $validation->getRounding(), 'notes' => $validation->getNotes(),
            'recorded_by' => $validation->getRecordedBy(), 'recorded_at' => $validation->getRecordedAt()->format(\DateTimeInterface::ATOM),
            'active' => $validation->isActive(), 'revoked_at' => $validation->getRevokedAt()?->format(\DateTimeInterface::ATOM),
            'revoked_by' => $validation->getRevokedBy(), 'revoke_reason' => $validation->getRevokeReason(),
        ];
    }

    /** @return array<string, string|null> */
    private function serializeLegalProfile(?OrganizationSettings $settings): array
    {
        return [
            'legal_name' => $settings?->getLegalName(), 'registration_number' => $settings?->getRegistrationNumber(), 'vat_number' => $settings?->getVatNumber(),
            'address' => $settings?->getLegalAddress(), 'email' => $settings?->getContactEmail(), 'payment_instructions' => $settings?->getPaymentInstructions(),
        ];
    }
}
