<?php

namespace App\Controller;

use App\Dto\TaskInput;
use App\Dto\TaskDependenciesInput;
use App\Entity\Task;
use App\Entity\TaskPriority;
use App\Entity\TaskStatus;
use App\Entity\User;
use App\Repository\ProjectRepository;
use App\Repository\TaskRepository;
use App\Repository\TaskDependencyRepository;
use App\Repository\UserRepository;
use App\Service\ClientActivityLogger;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/tasks', name: 'api_tasks_')]
class TaskController extends AbstractController
{
	public function __construct(
		private readonly TaskRepository $taskRepository,
		private readonly TaskDependencyRepository $dependencyRepository,
		private readonly ProjectRepository $projectRepository,
		private readonly UserRepository $userRepository,
		private readonly EntityManagerInterface $entityManager,
		private readonly ClientActivityLogger $activityLogger,
		#[Autowire(service: 'monolog.logger.audit')]
		private readonly LoggerInterface $auditLogger,
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
		$assignee = $input->assignee_id !== null ? $this->userRepository->find($input->assignee_id) : null;
		if ($input->assignee_id !== null && $assignee === null) {
			return $this->json(['error' => 'Task assignee not found.'], JsonResponse::HTTP_NOT_FOUND);
		}

		$now = new \DateTimeImmutable();
		$item = (new Task())
			->setTitle(trim($input->title))
			->setDescription(trim($input->description))
			->setProject($project)
			->setAssignee($assignee)
			->setStatus(TaskStatus::from($input->status ?? TaskStatus::Todo->value))
			->setPriority(TaskPriority::from($input->priority ?? TaskPriority::Normal->value))
			->setSchedule($input->start_datetime, $input->end_datetime)
			->setDueDate($input->due_date !== null ? new \DateTimeImmutable($input->due_date) : null)
			->setCreatedAt($now)
			->setUpdatedAt($now);

		$this->entityManager->persist($item);
		$this->activityLogger->record(
			$project->getClient(),
			$this->getUser() instanceof User ? $this->getUser() : null,
			'task_created',
			sprintf('Task "%s" was created.', $item->getTitle()),
		);
		$this->entityManager->flush();

		$this->auditLogger->info('Task created.', [
			'action' => 'task.create',
			'actor' => $this->getAuditActor(),
			'task_id' => $item->getId(),
		]);

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

		#[Route('/{id<\d+>}/dependencies', name: 'dependencies_list', methods: ['GET'])]
		#[IsGranted('ROLE_USER')]
		public function dependencies(int $id): JsonResponse
		{
			$task = $this->taskRepository->find($id);
			if (!$task instanceof Task) {
				return $this->json(['error' => 'Task not found.'], JsonResponse::HTTP_NOT_FOUND);
			}

			return $this->json(array_map(
				static fn ($dependency): array => [
					'id' => $dependency->getPredecessor()->getId(),
					'title' => $dependency->getPredecessor()->getTitle(),
				],
				$this->dependencyRepository->findForSuccessor($id),
			));
		}

		#[Route('/{id<\d+>}/dependencies', name: 'dependencies_update', methods: ['PUT'])]
		#[IsGranted('ROLE_USER')]
		public function updateDependencies(int $id, #[MapRequestPayload] TaskDependenciesInput $input): JsonResponse
		{
			$task = $this->taskRepository->find($id);
			if (!$task instanceof Task) {
				return $this->json(['error' => 'Task not found.'], JsonResponse::HTTP_NOT_FOUND);
			}
			$ids = [];
			foreach ($input->depends_on_ids as $predecessorId) {
				if (!is_int($predecessorId) || $predecessorId < 1 || isset($ids[$predecessorId])) {
					return $this->json(['error' => 'Dependency ids must be distinct positive integers.'], JsonResponse::HTTP_BAD_REQUEST);
				}
				$ids[$predecessorId] = true;
			}
			$predecessors = [];
			foreach (array_keys($ids) as $predecessorId) {
				$predecessor = $this->taskRepository->find($predecessorId);
				if (!$predecessor instanceof Task || $predecessor->getProject()?->getId() !== $task->getProject()?->getId()) {
					return $this->json(['error' => 'Dependencies must reference tasks in the same project.'], JsonResponse::HTTP_BAD_REQUEST);
				}
				$predecessors[] = $predecessor;
			}
			$adjacency = [];
			foreach ($this->dependencyRepository->findAll() as $dependency) {
				$adjacency[(int) $dependency->getPredecessor()->getId()][] = (int) $dependency->getSuccessor()->getId();
			}
			foreach ($predecessors as $predecessor) {
				$pending = [(int) $task->getId()];
				$visited = [];
				while ($pending !== []) {
					$current = array_pop($pending);
					if ($current === (int) $predecessor->getId()) {
						return $this->json(['error' => 'Task dependencies cannot form a cycle.'], JsonResponse::HTTP_CONFLICT);
					}
					if (isset($visited[$current])) {
						continue;
					}
					$visited[$current] = true;
					array_push($pending, ...($adjacency[$current] ?? []));
				}
			}
			foreach ($this->dependencyRepository->findForSuccessor($id) as $dependency) {
				$this->entityManager->remove($dependency);
			}
			foreach ($predecessors as $predecessor) {
				$this->entityManager->persist(new \App\Entity\TaskDependency($predecessor, $task));
			}
			$this->entityManager->flush();

			return $this->dependencies($id);
		}

	#[Route('/{id<\d+>}', name: 'update', methods: ['PUT', 'PATCH'])]
	#[IsGranted('ROLE_USER')]
	public function update(int $id, #[MapRequestPayload] TaskInput $input, Request $request): JsonResponse
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
			if ($item->getProject()?->getId() !== $project->getId()) {
				foreach ($this->dependencyRepository->findAll() as $dependency) {
					$relatedTask = $dependency->getPredecessor()->getId() === $item->getId()
						? $dependency->getSuccessor()
						: ($dependency->getSuccessor()->getId() === $item->getId() ? $dependency->getPredecessor() : null);
					if ($relatedTask !== null && $relatedTask->getProject()?->getId() !== $project->getId()) {
						return $this->json(['error' => 'Move or remove task dependencies before moving this task to another project.'], JsonResponse::HTTP_CONFLICT);
					}
				}
			}
			$item->setProject($project);
		}
		if ($input->assignee_id !== null) {
			$assignee = $this->userRepository->find($input->assignee_id);
			if ($assignee === null) {
				return $this->json(['error' => 'Task assignee not found.'], JsonResponse::HTTP_NOT_FOUND);
			}
			$item->setAssignee($assignee);
		} elseif (array_key_exists('assignee_id', $request->toArray())) {
			$item->setAssignee(null);
		}

		$item
			->setTitle($title)
			->setDescription($description)
			->setStatus(TaskStatus::from($input->status ?? $item->getStatus()->value))
			->setPriority(TaskPriority::from($input->priority ?? $item->getPriority()->value))
			->setSchedule($startDatetime, $endDatetime)
			->setDueDate($input->due_date !== null ? new \DateTimeImmutable($input->due_date) : $item->getDueDate())
			->setUpdatedAt(new \DateTimeImmutable());
		$this->activityLogger->record(
			$item->getProject()?->getClient(),
			$this->getUser() instanceof User ? $this->getUser() : null,
			'task_updated',
			sprintf('Task "%s" was updated.', $item->getTitle()),
		);
		$this->entityManager->flush();

		$this->auditLogger->info('Task updated.', [
			'action' => 'task.update',
			'actor' => $this->getAuditActor(),
			'task_id' => $item->getId(),
		]);

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

		$this->activityLogger->record(
			$item->getProject()?->getClient(),
			$this->getUser() instanceof User ? $this->getUser() : null,
			'task_deleted',
			sprintf('Task "%s" was deleted.', $item->getTitle()),
		);
		$this->entityManager->remove($item);
		$this->entityManager->flush();

		$this->auditLogger->info('Task deleted.', [
			'action' => 'task.delete',
			'actor' => $this->getAuditActor(),
			'task_id' => $id,
		]);

		return new JsonResponse(null, JsonResponse::HTTP_NO_CONTENT);
	}

	private function getAuditActor(): string
	{
		$user = $this->getUser();

		return $user instanceof User ? $user->getUserIdentifier() : 'anonymous';
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
			'assignee_id' => $item->getAssignee()?->getId(),
			'assignee_email' => $item->getAssignee()?->getUserIdentifier(),
			'status' => $item->getStatus()->value,
			'priority' => $item->getPriority()->value,
			'due_date' => $item->getDueDate()?->format('Y-m-d'),
			'due_status' => $item->getDueDate() === null ? null : ($item->getStatus() === TaskStatus::Done ? 'done' : ($item->getDueDate() < new \DateTimeImmutable('today') ? 'overdue' : 'upcoming')),
			'start_datetime' => $item->getStartDatetime()?->format(\DateTimeInterface::ATOM),
			'end_datetime' => $item->getEndDatetime()?->format(\DateTimeInterface::ATOM),
			'created_at' => $item->getCreatedAt()?->format(\DateTimeInterface::ATOM),
			'updated_at' => $item->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
		];
	}

}
