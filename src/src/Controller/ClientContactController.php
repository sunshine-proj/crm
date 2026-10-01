<?php

namespace App\Controller;

use App\Dto\ClientContactInput;
use App\Dto\ClientContactView;
use App\Entity\Client;
use App\Entity\ClientContact;
use App\Entity\User;
use App\Repository\ClientContactRepository;
use App\Repository\ClientRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Psr\Log\LoggerInterface;

#[Route('/api/clients/{clientId<\d+>}/contacts', name: 'api_client_contacts_')]
final class ClientContactController extends AbstractController
{
    public function __construct(
        private readonly ClientRepository $clientRepository,
        private readonly ClientContactRepository $contactRepository,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire(service: 'monolog.logger.audit')]
        private readonly LoggerInterface $auditLogger,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function list(int $clientId): JsonResponse
    {
        if ($this->clientRepository->find($clientId) === null) {
            return $this->json(['error' => 'Client not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        $contacts = $this->contactRepository->findBy(['client' => $clientId], ['name' => 'ASC']);

        return $this->json(array_map($this->toView(...), $contacts));
    }

    #[Route('', name: 'create', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function create(int $clientId, #[MapRequestPayload] ClientContactInput $input): JsonResponse
    {
        $client = $this->clientRepository->find($clientId);
        if ($client === null) {
            return $this->json(['error' => 'Client not found.'], JsonResponse::HTTP_NOT_FOUND);
        }
        if ($input->name === null || trim($input->name) === '') {
            return $this->json(['error' => 'Contact name is required.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $now = new \DateTimeImmutable();
        $contact = $this->applyInput(new ClientContact(), $client, $input)
            ->setCreatedAt($now)
            ->setUpdatedAt($now);

        $this->entityManager->persist($contact);
        $this->entityManager->flush();
        $this->auditLogger->info('Client contact created.', [
            'action' => 'client.contact.create',
            'actor' => $this->getAuditActor(),
            'client_id' => $clientId,
            'contact_id' => $contact->getId(),
        ]);

        return $this->json($this->toView($contact), JsonResponse::HTTP_CREATED);
    }

    #[Route('/{contactId<\d+>}', name: 'update', methods: ['PUT', 'PATCH'])]
    #[IsGranted('ROLE_ADMIN')]
    public function update(int $clientId, int $contactId, #[MapRequestPayload] ClientContactInput $input): JsonResponse
    {
        $contact = $this->findContactForClient($clientId, $contactId);
        if ($contact === null) {
            return $this->json(['error' => 'Client contact not found.'], JsonResponse::HTTP_NOT_FOUND);
        }
        if (($input->name !== null && trim($input->name) === '') || ($input->name === null && $contact->getName() === null)) {
            return $this->json(['error' => 'Contact name is required.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $client = $contact->getClient();
        if (!$client instanceof Client) {
            return $this->json(['error' => 'Client contact not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        $this->applyInput($contact, $client, $input)->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();
        $this->auditLogger->info('Client contact updated.', [
            'action' => 'client.contact.update',
            'actor' => $this->getAuditActor(),
            'client_id' => $clientId,
            'contact_id' => $contactId,
        ]);

        return $this->json($this->toView($contact));
    }

    #[Route('/{contactId<\d+>}', name: 'delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(int $clientId, int $contactId): JsonResponse
    {
        $contact = $this->findContactForClient($clientId, $contactId);
        if ($contact === null) {
            return $this->json(['error' => 'Client contact not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        $this->entityManager->remove($contact);
        $this->entityManager->flush();
        $this->auditLogger->info('Client contact deleted.', [
            'action' => 'client.contact.delete',
            'actor' => $this->getAuditActor(),
            'client_id' => $clientId,
            'contact_id' => $contactId,
        ]);

        return new JsonResponse(null, JsonResponse::HTTP_NO_CONTENT);
    }

    private function findContactForClient(int $clientId, int $contactId): ?ClientContact
    {
        $contact = $this->contactRepository->find($contactId);

        return $contact !== null && $contact->getClient()?->getId() === $clientId ? $contact : null;
    }

    private function applyInput(ClientContact $contact, Client $client, ClientContactInput $input): ClientContact
    {
        if ($input->name !== null) {
            $contact->setName(trim($input->name));
        }

        if ($input->job_title !== null) {
            $contact->setJobTitle($this->normalizeOptional($input->job_title));
        }
        if ($input->email !== null) {
            $contact->setEmail($this->normalizeOptional($input->email));
        }
        if ($input->phone !== null) {
            $contact->setPhone($this->normalizeOptional($input->phone));
        }

        return $contact->setClient($client);
    }

    private function normalizeOptional(string $value): ?string
    {
        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function getAuditActor(): string
    {
        $user = $this->getUser();

        return $user instanceof User ? $user->getUserIdentifier() : 'anonymous';
    }

    private function toView(ClientContact $contact): ClientContactView
    {
        return new ClientContactView(
            id: $contact->getId(),
            client_id: $contact->getClient()?->getId() ?? 0,
            name: $contact->getName() ?? '',
            job_title: $contact->getJobTitle(),
            email: $contact->getEmail(),
            phone: $contact->getPhone(),
            created_at: $contact->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            updated_at: $contact->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
        );
    }
}