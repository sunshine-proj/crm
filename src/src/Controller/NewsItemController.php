<?php

namespace App\Controller;

use App\Entity\NewsItem;
use App\Entity\User;
use App\Repository\NewsItemRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/news', name: 'api_news_')]
class NewsItemController extends AbstractController
{
    public function __construct(
        private readonly NewsItemRepository $newsItemRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $items = $this->newsItemRepository->findBy([], ['createdAt' => 'DESC']);

        return $this->json(array_map(fn (NewsItem $item): array => $this->serializeItem($item), $items));
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = $this->getRequestData($request);
        if ($data === null) {
            return $this->json(['error' => 'Request body must be valid JSON.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $title = $this->getTextField($data, 'title');
        $content = $this->getTextField($data, 'content');
        if ($title === null || $content === null) {
            return $this->json(['error' => 'Title and content are required.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $user = $this->getAuthenticatedUser();
        if ($user === null) {
            return $this->json(['error' => 'Authentication is required.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $now = new \DateTimeImmutable();
        $item = (new NewsItem())
            ->setTitle($title)
            ->setContent($content)
            ->setCreatedAt($now)
            ->setUpdatedAt($now)
            ->setUser($user);

        $this->entityManager->persist($item);
        $this->entityManager->flush();

        return $this->json($this->serializeItem($item), JsonResponse::HTTP_CREATED);
    }

    #[Route('/{id<\d+>}', name: 'show', methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        $item = $this->newsItemRepository->find($id);
        if ($item === null) {
            return $this->json(['error' => 'News item not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        return $this->json($this->serializeItem($item));
    }

    #[Route('/{id<\d+>}', name: 'update', methods: ['PUT', 'PATCH'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $item = $this->newsItemRepository->find($id);
        if ($item === null) {
            return $this->json(['error' => 'News item not found.'], JsonResponse::HTTP_NOT_FOUND);
        }
        if (!$this->isOwner($item)) {
            return $this->json(['error' => 'You can only edit your own news items.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $data = $this->getRequestData($request);
        if ($data === null) {
            return $this->json(['error' => 'Request body must be valid JSON.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $title = array_key_exists('title', $data) ? $this->getTextField($data, 'title') : $item->getTitle();
        $content = array_key_exists('content', $data) ? $this->getTextField($data, 'content') : $item->getContent();
        if ($title === null || $content === null) {
            return $this->json(['error' => 'Title and content are required.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $item->setTitle($title)->setContent($content)->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        return $this->json($this->serializeItem($item));
    }

    #[Route('/{id<\d+>}', name: 'delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $item = $this->newsItemRepository->find($id);
        if ($item === null) {
            return $this->json(['error' => 'News item not found.'], JsonResponse::HTTP_NOT_FOUND);
        }
        if (!$this->isOwner($item)) {
            return $this->json(['error' => 'You can only delete your own news items.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $this->entityManager->remove($item);
        $this->entityManager->flush();

        return new JsonResponse(null, JsonResponse::HTTP_NO_CONTENT);
    }

    /** @return array<string, mixed>|null */
    private function getRequestData(Request $request): ?array
    {
        try {
            $data = $request->toArray();
        } catch (\JsonException) {
            return null;
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    private function getTextField(array $data, string $field): ?string
    {
        $value = $data[$field] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function getAuthenticatedUser(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }

    private function isOwner(NewsItem $item): bool
    {
        $user = $this->getAuthenticatedUser();

        return $user !== null && $item->getUser()?->getId() === $user->getId();
    }

    /** @return array<string, int|string|null> */
    private function serializeItem(NewsItem $item): array
    {
        return [
            'id' => $item->getId(),
            'title' => $item->getTitle(),
            'content' => $item->getContent(),
            'created_at' => $item->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'updated_at' => $item->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
            'user_id' => $item->getUser()?->getId(),
        ];
    }
}