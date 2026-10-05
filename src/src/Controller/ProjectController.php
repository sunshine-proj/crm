<?php

namespace App\Controller;

use App\Dto\ProjectInput;
use App\Entity\ProjectStatus;
use App\Entity\Project;
use App\Entity\User;
use App\Repository\ClientRepository;
use App\Repository\ProjectRepository;
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

#[Route('/api/projects', name: 'api_projects_')]
class ProjectController extends AbstractController
{
	public function __construct(
		private readonly ProjectRepository $projectRepository,
		private readonly ClientRepository $clientRepository,
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
		$items = $this->projectRepository->findBy([], ['createdAt' => 'DESC']);

		return $this->json(array_map(fn (Project $item): array => $this->serializeItem($item), $items));
	}

	#[Route('/managers', name: 'managers', methods: ['GET'])]
	#[IsGranted('ROLE_USER')]
	public function managers(): JsonResponse
	{
		$users = $this->userRepository->findBy([], ['email' => 'ASC']);

		return $this->json(array_map(static fn ($user): array => [
			'id' => $user->getId(),
			'email' => $user->getUserIdentifier(),
		], $users));
	}

	#[Route('', name: 'create', methods: ['POST'])]
	#[IsGranted('ROLE_USER')]
	public function create(#[MapRequestPayload] ProjectInput $input): JsonResponse
	{
		if ($input->title === null || $input->description === null || $input->client_id === null) {
			return $this->json(['error' => 'Title, description and client_id are required.'], JsonResponse::HTTP_BAD_REQUEST);
		}

		$client = $this->clientRepository->find($input->client_id);
		if ($client === null) {
			return $this->json(['error' => 'Client not found.'], JsonResponse::HTTP_NOT_FOUND);
		}
		$manager = $input->manager_id !== null ? $this->userRepository->find($input->manager_id) : null;
		if ($input->manager_id !== null && $manager === null) {
			return $this->json(['error' => 'Project manager not found.'], JsonResponse::HTTP_NOT_FOUND);
		}

		$startDate = $input->start_date !== null ? new \DateTimeImmutable($input->start_date) : null;
		$endDate = $input->end_date !== null ? new \DateTimeImmutable($input->end_date) : null;
		if ($startDate !== null && $endDate !== null && $endDate < $startDate) {
			return $this->json(['error' => 'Project end date must be on or after its start date.'], JsonResponse::HTTP_BAD_REQUEST);
		}

		$now = new \DateTimeImmutable();
		$item = (new Project())
			->setTitle(trim($input->title))
			->setDescription(trim($input->description))
			->setClient($client)
			->setManager($manager)
			->setStatus(ProjectStatus::from($input->status ?? ProjectStatus::Planned->value))
			->setPlannedDates($startDate, $endDate)
			->setCreatedAt($now)
			->setUpdatedAt($now);

		$this->entityManager->persist($item);
		$this->activityLogger->record(
			$client,
			$this->getUser() instanceof User ? $this->getUser() : null,
			'project_created',
			sprintf('Project "%s" was created.', $item->getTitle()),
		);
		$this->entityManager->flush();

		$this->auditLogger->info('Project created.', [
			'action' => 'project.create',
			'actor' => $this->getAuditActor(),
			'project_id' => $item->getId(),
		]);

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
	public function update(int $id, #[MapRequestPayload] ProjectInput $input, Request $request): JsonResponse
	{
		$item = $this->projectRepository->find($id);
		if ($item === null) {
			return $this->json(['error' => 'Project not found.'], JsonResponse::HTTP_NOT_FOUND);
		}

		$title = $input->title !== null ? trim($input->title) : $item->getTitle();
		$description = $input->description !== null ? trim($input->description) : $item->getDescription();
		$startDate = $input->start_date !== null ? new \DateTimeImmutable($input->start_date) : $item->getStartDate();
		$endDate = $input->end_date !== null ? new \DateTimeImmutable($input->end_date) : $item->getEndDate();
		if ($title === null || $description === null) {
			return $this->json(['error' => 'Title and description are required.'], JsonResponse::HTTP_BAD_REQUEST);
		}
		if ($startDate !== null && $endDate !== null && $endDate < $startDate) {
			return $this->json(['error' => 'Project end date must be on or after its start date.'], JsonResponse::HTTP_BAD_REQUEST);
		}

		if ($input->client_id !== null) {
			$client = $this->clientRepository->find($input->client_id);
			if ($client === null) {
				return $this->json(['error' => 'Client not found.'], JsonResponse::HTTP_NOT_FOUND);
			}
			$item->setClient($client);
		}
		$payload = $request->toArray();
		if (array_key_exists('manager_id', $payload)) {
			$manager = $input->manager_id !== null ? $this->userRepository->find($input->manager_id) : null;
			if ($input->manager_id !== null && $manager === null) {
				return $this->json(['error' => 'Project manager not found.'], JsonResponse::HTTP_NOT_FOUND);
			}
			$item->setManager($manager);
		}

		$item
			->setTitle($title)
			->setDescription($description)
			->setStatus(ProjectStatus::from($input->status ?? $item->getStatus()->value))
			->setPlannedDates($startDate, $endDate)
			->setUpdatedAt(new \DateTimeImmutable());
		$this->activityLogger->record(
			$item->getClient(),
			$this->getUser() instanceof User ? $this->getUser() : null,
			'project_updated',
			sprintf('Project "%s" was updated.', $item->getTitle()),
		);
		$this->entityManager->flush();

		$this->auditLogger->info('Project updated.', [
			'action' => 'project.update',
			'actor' => $this->getAuditActor(),
			'project_id' => $item->getId(),
		]);

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

		$this->activityLogger->record(
			$item->getClient(),
			$this->getUser() instanceof User ? $this->getUser() : null,
			'project_deleted',
			sprintf('Project "%s" was deleted.', $item->getTitle()),
		);
		$this->entityManager->remove($item);
		$this->entityManager->flush();

		$this->auditLogger->info('Project deleted.', [
			'action' => 'project.delete',
			'actor' => $this->getAuditActor(),
			'project_id' => $id,
		]);

		return new JsonResponse(null, JsonResponse::HTTP_NO_CONTENT);
	}

	private function getAuditActor(): string
	{
		$user = $this->getUser();

		return $user instanceof User ? $user->getUserIdentifier() : 'anonymous';
	}

	/** @return array<string, int|string|null> */
	private function serializeItem(Project $item): array
	{
		return [
			'id' => $item->getId(),
			'title' => $item->getTitle(),
			'description' => $item->getDescription(),
			'client_id' => $item->getClient()?->getId(),
			'client_title' => $item->getClient()?->getTitle(),
			'manager_id' => $item->getManager()?->getId(),
			'manager_email' => $item->getManager()?->getUserIdentifier(),
			'status' => $item->getStatus()->value,
			'start_date' => $item->getStartDate()?->format('Y-m-d'),
			'end_date' => $item->getEndDate()?->format('Y-m-d'),
			'created_at' => $item->getCreatedAt()?->format(\DateTimeInterface::ATOM),
			'updated_at' => $item->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
		];
	}
}
