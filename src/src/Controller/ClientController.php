<?php

namespace App\Controller;

use App\Dto\ClientInput;
use App\Entity\Client;
use App\Entity\User;
use App\Repository\ClientRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/clients', name: 'api_clients_')]
class ClientController extends AbstractController
{
    public function __construct(
        private readonly ClientRepository $clientRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function list(): JsonResponse
    {
        $items = $this->clientRepository->findBy([], ['createdAt' => 'DESC']);

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

        $this->entityManager->persist($item);
        $this->entityManager->flush();

        return $this->json($this->serializeItem($item), JsonResponse::HTTP_CREATED);
    }

    #[Route('/{id<\d+>}', name: 'show', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function show(int $id): JsonResponse
    {
        $item = $this->clientRepository->find($id);
        if ($item === null) {
            return $this->json(['error' => 'Client not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        return $this->json($this->serializeItem($item));
    }

    #[Route('/{id<\d+>}', name: 'update', methods: ['PUT', 'PATCH'])]
    #[IsGranted('ROLE_ADMIN', message: 'User tried to access a page without having ROLE_ADMIN')]
    public function update(int $id, #[MapRequestPayload] ClientInput $input): JsonResponse
    {
        $item = $this->clientRepository->find($id);
        if ($item === null) {
            return $this->json(['error' => 'Client not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        $title = $input->title !== null ? trim($input->title) : $item->getTitle();
        $notes = $input->notes !== null ? trim($input->notes) : $item->getNotes();
        if ($title === null || $notes === null) {
            return $this->json(['error' => 'Title and notes are required.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $item->setTitle($title)->setNotes($notes)->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        return $this->json($this->serializeItem($item));
    }

    #[Route('/{id<\d+>}', name: 'delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADMIN', message: 'User tried to access a page without having ROLE_ADMIN')]
    public function delete(int $id): JsonResponse
    {
        $item = $this->clientRepository->find($id);
        if ($item === null) {
            return $this->json(['error' => 'Client not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        $this->entityManager->remove($item);
        $this->entityManager->flush();

        return new JsonResponse(null, JsonResponse::HTTP_NO_CONTENT);
    }

    /** @return array<string, int|string|null> */
    private function serializeItem(Client $item): array
    {
        return [
            'id' => $item->getId(),
            'title' => $item->getTitle(),
            'notes' => $item->getNotes(),
            'created_at' => $item->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'updated_at' => $item->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
