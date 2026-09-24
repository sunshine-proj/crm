<?php

namespace App\Controller;

use App\Dto\TaskInput;
use App\Entity\Task;
use App\Repository\TaskRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/tasks', name: 'api_tasks_')]
class TaskController extends AbstractController
{
	public function __construct(
		private readonly TaskRepository $taskRepository,
		private readonly EntityManagerInterface $entityManager,
	) {
	}

	#[Route('', name: 'list', methods: ['GET'])]
	#[IsGranted('ROLE_USER')]
	public function list(): JsonResponse
	{
		$items = $this->taskRepository->findBy([], ['startDatetime' => 'ASC']);

		return $this->json(array_map(fn (Task $item): array => $this->serializeItem($item), $items));
	}

	#[Route('', name: 'create', methods: ['POST'])]
	#[IsGranted('ROLE_USER')]
	public function create(#[MapRequestPayload] TaskInput $input): JsonResponse
	{
		if ($input->title === null || $input->description === null || $input->start_datetime === null || $input->end_datetime === null) {
			return $this->json(['error' => 'Title, description, start_datetime and end_datetime are required.'], JsonResponse::HTTP_BAD_REQUEST);
		}

		$now = new \DateTimeImmutable();
		$item = (new Task())
			->setTitle(trim($input->title))
			->setDescription(trim($input->description))
			->setStartDatetime($input->start_datetime)
			->setEndDatetime($input->end_datetime)
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
		$item = $this->taskRepository->find($id);
		if ($item === null) {
			return $this->json(['error' => 'Task not found.'], JsonResponse::HTTP_NOT_FOUND);
		}

		return $this->json($this->serializeItem($item));
	}

	#[Route('/{id<\d+>}', name: 'update', methods: ['PUT', 'PATCH'])]
	#[IsGranted('ROLE_USER')]
	public function update(int $id, #[MapRequestPayload] TaskInput $input): JsonResponse
	{
		$item = $this->taskRepository->find($id);
		if ($item === null) {
			return $this->json(['error' => 'Task not found.'], JsonResponse::HTTP_NOT_FOUND);
		}

		$title = $input->title !== null ? trim($input->title) : $item->getTitle();
		$description = $input->description !== null ? trim($input->description) : $item->getDescription();
		if ($title === null || $description === null || $input->start_datetime === null && $item->getStartDatetime() === null || $input->end_datetime === null && $item->getEndDatetime() === null) {
			return $this->json(['error' => 'Title, description, start_datetime and end_datetime are required.'], JsonResponse::HTTP_BAD_REQUEST);
		}

		$item
			->setTitle($title)
			->setDescription($description)
			->setStartDatetime($input->start_datetime ?? $item->getStartDatetime())
			->setEndDatetime($input->end_datetime ?? $item->getEndDatetime())
			->setUpdatedAt(new \DateTimeImmutable());
		$this->entityManager->flush();

		return $this->json($this->serializeItem($item));
	}

	#[Route('/{id<\d+>}', name: 'delete', methods: ['DELETE'])]
	#[IsGranted('ROLE_USER')]
	public function delete(int $id): JsonResponse
	{
		$item = $this->taskRepository->find($id);
		if ($item === null) {
			return $this->json(['error' => 'Task not found.'], JsonResponse::HTTP_NOT_FOUND);
		}

		$this->entityManager->remove($item);
		$this->entityManager->flush();

		return new JsonResponse(null, JsonResponse::HTTP_NO_CONTENT);
	}

	/** @return array<string, int|string|null> */
	private function serializeItem(Task $item): array
	{
		return [
			'id' => $item->getId(),
			'title' => $item->getTitle(),
			'description' => $item->getDescription(),
			'start_datetime' => $item->getStartDatetime()?->format(\DateTimeInterface::ATOM),
			'end_datetime' => $item->getEndDatetime()?->format(\DateTimeInterface::ATOM),
			'created_at' => $item->getCreatedAt()?->format(\DateTimeInterface::ATOM),
			'updated_at' => $item->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
		];
	}

}
