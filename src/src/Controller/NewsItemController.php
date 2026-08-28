<?php

namespace App\Controller;

use App\Dto\NewsItemInput;
use App\Entity\NewsItem;
use App\Entity\User;
use App\Repository\NewsItemRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
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
    public function create(#[MapRequestPayload] NewsItemInput $input): JsonResponse
    {
        if ($input->title === null || $input->content === null) {
            return $this->json(['error' => 'Title and content are required.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $user = $this->getAuthenticatedUser();
        if ($user === null) {
            return $this->json(['error' => 'Authentication is required.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $now = new \DateTimeImmutable();
        $item = (new NewsItem())
            ->setTitle(trim($input->title))
            ->setContent(trim($input->content))
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
    public function update(int $id, #[MapRequestPayload] NewsItemInput $input): JsonResponse
    {
        $item = $this->newsItemRepository->find($id);
        if ($item === null) {
            return $this->json(['error' => 'News item not found.'], JsonResponse::HTTP_NOT_FOUND);
        }
        if (!$this->isOwner($item)) {
            return $this->json(['error' => 'You can only edit your own news items.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $title = $input->title !== null ? trim($input->title) : $item->getTitle();
        $content = $input->content !== null ? trim($input->content) : $item->getContent();
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