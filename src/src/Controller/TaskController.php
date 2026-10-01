<?php

namespace App\Controller;

use App\Dto\TaskInput;
use App\Entity\Task;
use App\Entity\TaskPriority;
use App\Entity\TaskStatus;
use App\Repository\ProjectRepository;
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
		private readonly ProjectRepository $projectRepository,
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
		if ($input->title === null || $input->description === null || $input->project_id === null || $input->start_datetime === null || $input->end_datetime === null) {
			return $this->json(['error' => 'Title, description, project_id, start_datetime and end_datetime are required.'], JsonResponse::HTTP_BAD_REQUEST);
		}
		if ($input->end_datetime < $input->start_datetime) {
			return $this->json(['error' => 'Task end must be on or after its start.'], JsonResponse::HTTP_BAD_REQUEST);
		}

		$project = $this->projectRepository->find($input->project_id);
		if ($project === null) {
			return $this->json(['error' => 'Project not found.'], JsonResponse::HTTP_NOT_FOUND);
		}

		$now = new \DateTimeImmutable();
		$item = (new Task())
			->setTitle(trim($input->title))
			->setDescription(trim($input->description))
			->setProject($project)
			->setStatus(TaskStatus::from($input->status ?? TaskStatus::Todo->value))
			->setPriority(TaskPriority::from($input->priority ?? TaskPriority::Normal->value))
			->setSchedule($input->start_datetime, $input->end_datetime)
			->setDueDate($input->due_date !== null ? new \DateTimeImmutable($input->due_date) : null)
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
		$startDatetime = $input->start_datetime ?? $item->getStartDatetime();
		$endDatetime = $input->end_datetime ?? $item->getEndDatetime();
		if ($title === null || $description === null || $startDatetime === null || $endDatetime === null) {
			return $this->json(['error' => 'Title, description, start_datetime and end_datetime are required.'], JsonResponse::HTTP_BAD_REQUEST);
		}
		if ($endDatetime < $startDatetime) {
			return $this->json(['error' => 'Task end must be on or after its start.'], JsonResponse::HTTP_BAD_REQUEST);
		}

		if ($input->project_id !== null) {
			$project = $this->projectRepository->find($input->project_id);
			if ($project === null) {
				return $this->json(['error' => 'Project not found.'], JsonResponse::HTTP_NOT_FOUND);
			}
			$item->setProject($project);
		}

		$item
			->setTitle($title)
			->setDescription($description)
			->setStatus(TaskStatus::from($input->status ?? $item->getStatus()->value))
			->setPriority(TaskPriority::from($input->priority ?? $item->getPriority()->value))
			->setSchedule($startDatetime, $endDatetime)
			->setDueDate($input->due_date !== null ? new \DateTimeImmutable($input->due_date) : $item->getDueDate())
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
			'project_id' => $item->getProject()?->getId(),
			'project_title' => $item->getProject()?->getTitle(),
			'status' => $item->getStatus()->value,
			'priority' => $item->getPriority()->value,
			'due_date' => $item->getDueDate()?->format('Y-m-d'),
			'start_datetime' => $item->getStartDatetime()?->format(\DateTimeInterface::ATOM),
			'end_datetime' => $item->getEndDatetime()?->format(\DateTimeInterface::ATOM),
			'created_at' => $item->getCreatedAt()?->format(\DateTimeInterface::ATOM),
			'updated_at' => $item->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
		];
	}

}
