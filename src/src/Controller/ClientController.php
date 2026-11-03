<?php

namespace App\Controller;

use App\Dto\ClientInput;
use App\Entity\Client;
use App\Entity\User;
use App\Repository\ClientRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Psr\Log\LoggerInterface;

#[Route('/api/clients', name: 'api_clients_')]
class ClientController extends AbstractController
{
    public function __construct(
        private readonly ClientRepository $clientRepository,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire(service: 'monolog.logger.audit')]
        private readonly LoggerInterface $auditLogger,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function list(Request $request): JsonResponse
    {
        $status = $request->query->get('account_status');
        if ($status !== null && !in_array($status, Client::ACCOUNT_STATUSES, true)) {
            return $this->json(['error' => 'Unsupported account status filter.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        $country = $request->query->get('country_code');
        if ($country !== null && preg_match('/^[A-Za-z]{2}$/D', (string) $country) !== 1) {
            return $this->json(['error' => 'Country filter must be a two-letter code.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        $term = $request->query->get('q');
        $items = $this->clientRepository->search(
            $term !== null ? trim((string) $term) : null,
            $status !== null ? (string) $status : null,
            $country !== null ? strtoupper((string) $country) : null,
            $this->isGranted('ROLE_FINANCE'),
        );
        $this->auditLogger->info('Client list requested.', [
            'action' => 'client.list',
            'actor' => $this->getAuditActor(),
            'result_count' => count($items),
        ]);

        return $this->json(array_map(fn (Client $item): array => $this->serializeItem($item), $items));
    }

    #[Route('', name: 'create', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN', message: 'User tried to access a page without having ROLE_ADMIN')]
    public function create(#[MapRequestPayload] ClientInput $input): JsonResponse
    {
        // $this->denyAccessUnlessGranted('ROLE_ADMIN', null, 'User tried to access a page without having ROLE_ADMIN');

        if ($input->title === null || $input->notes === null) {
            return $this->json(['error' => 'Title and content are required.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        // $user = $this->getAuthenticatedUser();
        // if ($user === null) {
        //     return $this->json(['error' => 'Authentication is required.'], JsonResponse::HTTP_UNAUTHORIZED);
        // }

        $now = new \DateTimeImmutable();
        $item = (new Client())
            ->setTitle(trim($input->title))
            ->setNotes(trim($input->notes))
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $profileError = $this->applyProfile($item, $input);
        if ($profileError !== null) {
            return $this->json(['error' => $profileError], JsonResponse::HTTP_BAD_REQUEST);
        }

        $this->entityManager->persist($item);
        $this->entityManager->flush();
        $this->auditLogger->info('Client created.', [
            'action' => 'client.create',
            'actor' => $this->getAuditActor(),
            'client_id' => $item->getId(),
        ]);

        return $this->json($this->serializeItem($item), JsonResponse::HTTP_CREATED);
    }

    #[Route('/{id<\d+>}', name: 'show', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function show(int $id): JsonResponse
    {
        $item = $this->clientRepository->find($id);
        if ($item === null) {
            $this->auditLogger->info('Client lookup failed.', [
                'action' => 'client.show',
                'actor' => $this->getAuditActor(),
                'client_id' => $id,
                'outcome' => 'not_found',
            ]);

            return $this->json(['error' => 'Client not found.'], JsonResponse::HTTP_NOT_FOUND);
        }
        $this->auditLogger->info('Client viewed.', [
            'action' => 'client.show',
            'actor' => $this->getAuditActor(),
            'client_id' => $item->getId(),
        ]);

        return $this->json($this->serializeItem($item));
    }

    #[Route('/{id<\d+>}', name: 'update', methods: ['PUT', 'PATCH'])]
    #[IsGranted('ROLE_ADMIN', message: 'User tried to access a page without having ROLE_ADMIN')]
    public function update(int $id, #[MapRequestPayload] ClientInput $input): JsonResponse
    {
        $item = $this->clientRepository->find($id);
        if ($item === null) {
            $this->auditLogger->info('Client update failed.', [
                'action' => 'client.update',
                'actor' => $this->getAuditActor(),
                'client_id' => $id,
                'outcome' => 'not_found',
            ]);

            return $this->json(['error' => 'Client not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        $title = $input->title !== null ? trim($input->title) : $item->getTitle();
        $notes = $input->notes !== null ? trim($input->notes) : $item->getNotes();
        if ($title === null || $notes === null) {
            return $this->json(['error' => 'Title and notes are required.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $profileError = $this->applyProfile($item, $input);
        if ($profileError !== null) {
            return $this->json(['error' => $profileError], JsonResponse::HTTP_BAD_REQUEST);
        }
        $item->setTitle($title)->setNotes($notes)->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();
        $this->auditLogger->info('Client updated.', [
            'action' => 'client.update',
            'actor' => $this->getAuditActor(),
            'client_id' => $item->getId(),
        ]);

        return $this->json($this->serializeItem($item));
    }

    #[Route('/{id<\d+>}', name: 'delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADMIN', message: 'User tried to access a page without having ROLE_ADMIN')]
    public function delete(int $id): JsonResponse
    {
        $item = $this->clientRepository->find($id);
        if ($item === null) {
            $this->auditLogger->info('Client deletion failed.', [
                'action' => 'client.delete',
                'actor' => $this->getAuditActor(),
                'client_id' => $id,
                'outcome' => 'not_found',
            ]);

            return $this->json(['error' => 'Client not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        $this->entityManager->remove($item);
        $this->entityManager->flush();
        $this->auditLogger->info('Client deleted.', [
            'action' => 'client.delete',
            'actor' => $this->getAuditActor(),
            'client_id' => $id,
        ]);

        return new JsonResponse(null, JsonResponse::HTTP_NO_CONTENT);
    }

    private function getAuditActor(): string
    {
        $user = $this->getUser();

        return $user instanceof User ? $user->getUserIdentifier() : 'anonymous';
    }

    /** Updates only the account-profile fields present in the payload; an empty string clears a field. */
    private function applyProfile(Client $client, ClientInput $input): ?string
    {
        $clean = static fn (?string $new, ?string $current): ?string => $new === null ? $current : (trim($new) === '' ? null : trim($new));
        $country = $clean($input->country_code, $client->getCountryCode());
        $email = $clean($input->billing_email, $client->getBillingEmail());
        $website = $clean($input->website, $client->getWebsite());
        $vat = $clean($input->vat_number, $client->getVatNumber());
        $registration = $clean($input->registration_number, $client->getRegistrationNumber());
        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return 'Billing e-mail must be a valid address.';
        }
        if ($website !== null && preg_match('#^https?://[^\s]+$#i', $website) !== 1) {
            return 'Website must start with http:// or https://.';
        }
        if ($vat !== null && preg_match('/^[A-Za-z0-9][A-Za-z0-9 .-]{2,31}$/D', $vat) !== 1) {
            return 'VAT number may contain only letters, digits, spaces, dots, and hyphens (3-32 characters).';
        }
        if ($registration !== null && preg_match('#^[A-Za-z0-9][A-Za-z0-9 ./-]{0,63}$#D', $registration) !== 1) {
            return 'Registration number contains unsupported characters.';
        }
        $client->setAccountProfile(
            $clean($input->legal_name, $client->getLegalName()),
            $registration,
            $vat !== null ? strtoupper($vat) : null,
            $country !== null ? strtoupper($country) : null,
            $clean($input->billing_address, $client->getBillingAddress()),
            $email,
            $website,
            $clean($input->industry, $client->getIndustry()),
            $input->account_status ?? $client->getAccountStatus(),
        );

        return null;
    }

    /** @return array<string, bool|int|string|null> */
    private function serializeItem(Client $item): array
    {
        // Tax identifiers, billing address and billing e-mail are visible to finance users and administrators only.
        $canSeeFinancials = $this->isGranted('ROLE_FINANCE');

        return [
            'id' => $item->getId(),
            'title' => $item->getTitle(),
            'notes' => $item->getNotes(),
            'legal_name' => $item->getLegalName(),
            'country_code' => $item->getCountryCode(),
            'website' => $item->getWebsite(),
            'industry' => $item->getIndustry(),
            'account_status' => $item->getAccountStatus(),
            'registration_number' => $canSeeFinancials ? $item->getRegistrationNumber() : null,
            'vat_number' => $canSeeFinancials ? $item->getVatNumber() : null,
            'billing_address' => $canSeeFinancials ? $item->getBillingAddress() : null,
            'billing_email' => $canSeeFinancials ? $item->getBillingEmail() : null,
            'financial_details_restricted' => !$canSeeFinancials,
            'created_at' => $item->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'updated_at' => $item->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
