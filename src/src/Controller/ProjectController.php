<?php

namespace App\Controller;

use App\Dto\ProjectInput;
use App\Entity\Project;
use App\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/projects', name: 'api_projects_')]
class ProjectController extends AbstractController
{
	public function __construct(
		private readonly ProjectRepository $projectRepository,
		private readonly EntityManagerInterface $entityManager,
	) {
	}

	#[Route('', name: 'list', methods: ['GET'])]
	#[IsGranted('ROLE_USER')]
	public function list(): JsonResponse
	{
		$items = $this->projectRepository->findBy([], ['createdAt' => 'DESC']);

		return $this->json(array_map(fn (Project $item): array => $this->serializeItem($item), $items));
	}

	#[Route('', name: 'create', methods: ['POST'])]
	#[IsGranted('ROLE_USER')]
	public function create(#[MapRequestPayload] ProjectInput $input): JsonResponse
	{
		if ($input->title === null || $input->description === null) {
			return $this->json(['error' => 'Title and description are required.'], JsonResponse::HTTP_BAD_REQUEST);
		}

		$now = new \DateTimeImmutable();
		$item = (new Project())
			->setTitle(trim($input->title))
			->setDescription(trim($input->description))
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
		$item = $this->projectRepository->find($id);
		if ($item === null) {
			return $this->json(['error' => 'Project not found.'], JsonResponse::HTTP_NOT_FOUND);
		}

		return $this->json($this->serializeItem($item));
	}

	#[Route('/{id<\d+>}', name: 'update', methods: ['PUT', 'PATCH'])]
	#[IsGranted('ROLE_USER')]
	public function update(int $id, #[MapRequestPayload] ProjectInput $input): JsonResponse
	{
		$item = $this->projectRepository->find($id);
		if ($item === null) {
			return $this->json(['error' => 'Project not found.'], JsonResponse::HTTP_NOT_FOUND);
		}

		$title = $input->title !== null ? trim($input->title) : $item->getTitle();
		$description = $input->description !== null ? trim($input->description) : $item->getDescription();
		if ($title === null || $description === null) {
			return $this->json(['error' => 'Title and description are required.'], JsonResponse::HTTP_BAD_REQUEST);
		}

		$item
			->setTitle($title)
			->setDescription($description)
			->setUpdatedAt(new \DateTimeImmutable());
		$this->entityManager->flush();

		return $this->json($this->serializeItem($item));
	}

	#[Route('/{id<\d+>}', name: 'delete', methods: ['DELETE'])]
	#[IsGranted('ROLE_USER')]
	public function delete(int $id): JsonResponse
	{
		$item = $this->projectRepository->find($id);
		if ($item === null) {
			return $this->json(['error' => 'Project not found.'], JsonResponse::HTTP_NOT_FOUND);
		}

		$this->entityManager->remove($item);
		$this->entityManager->flush();

		return new JsonResponse(null, JsonResponse::HTTP_NO_CONTENT);
	}

	/** @return array<string, int|string|null> */
	private function serializeItem(Project $item): array
	{
		return [
			'id' => $item->getId(),
			'title' => $item->getTitle(),
			'description' => $item->getDescription(),
			'created_at' => $item->getCreatedAt()?->format(\DateTimeInterface::ATOM),
			'updated_at' => $item->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
		];
	}
}
