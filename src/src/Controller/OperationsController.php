<?php

namespace App\Controller;

use App\Dto\ExpenseDecisionInput;
use App\Dto\ExpenseInput;
use App\Dto\TimeEntryInput;
use App\Entity\Expense;
use App\Entity\ExpenseApproval;
use App\Entity\Project;
use App\Entity\ProjectDocument;
use App\Entity\Task;
use App\Entity\TimeEntry;
use App\Entity\User;
use App\Repository\ExpenseApprovalRepository;
use App\Repository\ExpenseRepository;
use App\Repository\ProjectDocumentRepository;
use App\Repository\ProjectRepository;
use App\Repository\TaskRepository;
use App\Repository\TimeEntryRepository;
use App\Service\FinanceCatalog;
use App\Service\InvoiceMoney;
use App\Service\ProjectDocumentStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/operations', name: 'api_operations_')]
#[IsGranted('ROLE_USER')]
final class OperationsController extends AbstractController
{
    public function __construct(
        private readonly ProjectRepository $projectRepository,
        private readonly TaskRepository $taskRepository,
        private readonly TimeEntryRepository $timeEntryRepository,
        private readonly ExpenseRepository $expenseRepository,
        private readonly ExpenseApprovalRepository $approvalRepository,
        private readonly ProjectDocumentRepository $documentRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly InvoiceMoney $money,
        private readonly FinanceCatalog $financeCatalog,
        private readonly ProjectDocumentStorage $documentStorage,
    ) {
    }

    #[Route('/time-entries', name: 'time_entries_list', methods: ['GET'])]
    public function timeEntries(Request $request): JsonResponse
    {
        $projectId = $this->parseOptionalId($request->query->get('project_id'));
        if (isset($projectId['error'])) return $this->json(['error' => $projectId['error']], JsonResponse::HTTP_BAD_REQUEST);
        if ($projectId['id'] === null) return $this->json(['error' => 'Choose a project to list time entries.'], JsonResponse::HTTP_BAD_REQUEST);
        if ($this->projectRepository->find($projectId['id']) === null) return $this->json(['error' => 'Project not found.'], JsonResponse::HTTP_NOT_FOUND);
        return $this->json(array_map(fn (TimeEntry $entry): array => $this->serializeTimeEntry($entry), $this->timeEntryRepository->findForProject($projectId['id'])));
    }

    #[Route('/time-entries', name: 'time_entries_create', methods: ['POST'])]
    public function createTimeEntry(#[MapRequestPayload] TimeEntryInput $input): JsonResponse
    {
        if ($input->project_id === null || $input->work_date === null || $input->duration_minutes === null || $input->description === null || trim($input->description) === '') {
            return $this->json(['error' => 'Project, work date, duration, and description are required.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        $project = $this->projectRepository->find($input->project_id);
        if ($project === null) return $this->json(['error' => 'Project not found.'], JsonResponse::HTTP_NOT_FOUND);
        try { $workDate = $this->parseDate($input->work_date); }
        catch (\InvalidArgumentException) { return $this->json(['error' => 'Work date must use YYYY-MM-DD.'], JsonResponse::HTTP_BAD_REQUEST); }
        $task = null;
        if ($input->task_id !== null) {
            $task = $this->taskRepository->find($input->task_id);
            if ($task === null || $task->getProject()?->getId() !== $project->getId()) return $this->json(['error' => 'Task must belong to the selected project.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        $user = $this->getUser();
        if (!$user instanceof User) return $this->json(['error' => 'Authenticated user is unavailable.'], JsonResponse::HTTP_UNAUTHORIZED);
        if ($input->duration_minutes < 1 || $input->duration_minutes > 1440 || mb_strlen(trim($input->description)) > 255) return $this->json(['error' => 'Duration must be 1-1440 minutes and description at most 255 characters.'], JsonResponse::HTTP_BAD_REQUEST);
        $entry = new TimeEntry($project, $task, $user, $workDate, $input->duration_minutes, $input->billable, trim($input->description));
        $this->entityManager->persist($entry);
        $this->entityManager->flush();
        return $this->json($this->serializeTimeEntry($entry), JsonResponse::HTTP_CREATED);
    }

    #[Route('/expenses', name: 'expenses_list', methods: ['GET'])]
    public function expenses(Request $request): JsonResponse
    {
        $status = $request->query->get('status');
        if ($status !== null && !in_array($status, ['pending_approval', 'approved', 'rejected'], true)) return $this->json(['error' => 'Invalid expense status filter.'], JsonResponse::HTTP_BAD_REQUEST);
        $projectId = $this->parseOptionalId($request->query->get('project_id'));
        if (isset($projectId['error'])) return $this->json(['error' => $projectId['error']], JsonResponse::HTTP_BAD_REQUEST);
        $currentUser = $this->getUser();
        $isAdmin = $currentUser instanceof User && in_array('ROLE_ADMIN', $currentUser->getRoles(), true);
        $items = $this->expenseRepository->findFiltered($projectId['id'], $status);
        if (!$isAdmin && $currentUser instanceof User) $items = array_values(array_filter($items, static fn (Expense $expense): bool => $expense->getSubmitter()->getId() === $currentUser->getId()));
        return $this->json(array_map(fn (Expense $expense): array => $this->serializeExpense($expense, true), $items));
    }

    #[Route('/expenses', name: 'expenses_create', methods: ['POST'])]
    public function createExpense(#[MapRequestPayload] ExpenseInput $input): JsonResponse
    {
        if ($input->project_id === null || $input->description === null || trim($input->description) === '' || $input->amount === null || $input->currency === null || $input->expense_date === null) {
            return $this->json(['error' => 'Project, description, amount, currency, and expense date are required.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        $project = $this->projectRepository->find($input->project_id);
        if ($project === null) return $this->json(['error' => 'Project not found.'], JsonResponse::HTTP_NOT_FOUND);
        if (!$this->financeCatalog->supportsCurrency($input->currency)) return $this->json(['error' => 'Currency must be EUR, GBP, or USD.'], JsonResponse::HTTP_BAD_REQUEST);
        if (mb_strlen(trim($input->description)) > 255) return $this->json(['error' => 'Expense description is too long.'], JsonResponse::HTTP_BAD_REQUEST);
        $amount = $this->money->parseMinor($input->amount);
        if ($amount === null || $amount < 1) return $this->json(['error' => 'Expense amount must be positive with at most two decimals.'], JsonResponse::HTTP_BAD_REQUEST);
        try { $expenseDate = $this->parseDate($input->expense_date); }
        catch (\InvalidArgumentException) { return $this->json(['error' => 'Expense date must use YYYY-MM-DD.'], JsonResponse::HTTP_BAD_REQUEST); }
        $user = $this->getUser();
        if (!$user instanceof User) return $this->json(['error' => 'Authenticated user is unavailable.'], JsonResponse::HTTP_UNAUTHORIZED);
        $expense = new Expense($project, $user, trim($input->description), $amount, $input->currency, $expenseDate);
        $this->entityManager->persist($expense);
        $this->entityManager->flush();
        return $this->json($this->serializeExpense($expense, true), JsonResponse::HTTP_CREATED);
    }

    #[Route('/expenses/pending-approval', name: 'expenses_pending', methods: ['GET'])]
    public function pendingExpenses(): JsonResponse
    {
        $actor = $this->getUser();
        if (!$actor instanceof User) return $this->json(['error' => 'Authenticated user is unavailable.'], JsonResponse::HTTP_UNAUTHORIZED);
        $isAdmin = in_array('ROLE_ADMIN', $actor->getRoles(), true);
        $items = $this->expenseRepository->findFiltered(null, 'pending_approval');
        if (!$isAdmin) $items = array_values(array_filter($items, static fn (Expense $expense): bool => $expense->getProject()->getManager()?->getId() === $actor->getId()));
        return $this->json(array_map(fn (Expense $expense): array => $this->serializeExpense($expense, true), $items));
    }

    #[Route('/expenses/{id<\d+>}/decision', name: 'expense_decision', methods: ['POST'])]
    public function decideExpense(int $id, #[MapRequestPayload] ExpenseDecisionInput $input): JsonResponse
    {
        $expense = $this->expenseRepository->find($id);
        if ($expense === null) return $this->json(['error' => 'Expense not found.'], JsonResponse::HTTP_NOT_FOUND);
        $actor = $this->getUser();
        if (!$actor instanceof User) return $this->json(['error' => 'Authenticated user is unavailable.'], JsonResponse::HTTP_UNAUTHORIZED);
        $isAdmin = in_array('ROLE_ADMIN', $actor->getRoles(), true);
        $isManager = $expense->getProject()->getManager()?->getId() === $actor->getId();
        if (!$isAdmin && !$isManager) return $this->json(['error' => 'Only an administrator or the project manager may decide this expense.'], JsonResponse::HTTP_FORBIDDEN);
        if ($input->decision === null || !in_array($input->decision, ['approved', 'rejected'], true)) return $this->json(['error' => 'Decision must be approved or rejected.'], JsonResponse::HTTP_BAD_REQUEST);
        if ($expense->getSubmitter()->getId() === $actor->getId() && !$isAdmin) return $this->json(['error' => 'Expense submitters cannot approve their own expenses.'], JsonResponse::HTTP_FORBIDDEN);
        try { $expense->decide($input->decision); }
        catch (\DomainException $exception) { return $this->json(['error' => $exception->getMessage()], JsonResponse::HTTP_CONFLICT); }
        $approval = new ExpenseApproval($expense, $actor, $input->decision, $input->comment !== null ? trim($input->comment) : null);
        $this->entityManager->persist($approval);
        $this->entityManager->flush();
        return $this->json($this->serializeExpense($expense, true));
    }

    #[Route('/projects/{projectId<\d+>}/documents', name: 'documents_list', methods: ['GET'])]
    public function projectDocuments(int $projectId): JsonResponse
    {
        if ($this->projectRepository->find($projectId) === null) return $this->json(['error' => 'Project not found.'], JsonResponse::HTTP_NOT_FOUND);
        return $this->json(array_map(fn (ProjectDocument $document): array => $this->serializeDocument($document), $this->documentRepository->findForProject($projectId)));
    }

    #[Route('/projects/{projectId<\d+>}/documents', name: 'documents_upload', methods: ['POST'])]
    public function uploadProjectDocument(int $projectId, Request $request): JsonResponse
    {
        $project = $this->projectRepository->find($projectId);
        if ($project === null) return $this->json(['error' => 'Project not found.'], JsonResponse::HTTP_NOT_FOUND);
        $file = $request->files->get('file');
        if (!$file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) return $this->json(['error' => 'Upload a file using the file field.'], JsonResponse::HTTP_BAD_REQUEST);
        try { $stored = $this->documentStorage->store($file); }
        catch (\InvalidArgumentException $exception) { return $this->json(['error' => $exception->getMessage()], JsonResponse::HTTP_BAD_REQUEST); }
        $user = $this->getUser();
        $document = new ProjectDocument($project, $user instanceof User ? $user : null, $stored['original_name'], $stored['stored_name'], $stored['mime_type'], $stored['size_bytes']);
        try {
            $this->entityManager->persist($document);
            $this->entityManager->flush();
        } catch (\Throwable $exception) {
            $this->documentStorage->delete($stored['stored_name']);
            throw $exception;
        }
        return $this->json($this->serializeDocument($document), JsonResponse::HTTP_CREATED);
    }

    #[Route('/documents/{id<\d+>}/download', name: 'documents_download', methods: ['GET'])]
    public function downloadProjectDocument(int $id): Response
    {
        $document = $this->documentRepository->find($id);
        if ($document === null) return $this->json(['error' => 'Document not found.'], JsonResponse::HTTP_NOT_FOUND);
        $path = $this->documentStorage->path($document->getStoredName());
        if (!is_file($path)) return $this->json(['error' => 'Document file is missing from storage.'], JsonResponse::HTTP_NOT_FOUND);
        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', $document->getMimeType());
        $response->setContentDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $document->getOriginalName());
        return $response;
    }

    #[Route('/documents/{id<\d+>}', name: 'documents_delete', methods: ['DELETE'])]
    public function deleteProjectDocument(int $id): Response
    {
        $document = $this->documentRepository->find($id);
        if ($document === null) return $this->json(['error' => 'Document not found.'], JsonResponse::HTTP_NOT_FOUND);
        $actor = $this->getUser();
        $isAdmin = $actor instanceof User && in_array('ROLE_ADMIN', $actor->getRoles(), true);
        $isUploader = $actor instanceof User && $document->getUploadedBy()?->getId() === $actor->getId();
        if (!$isAdmin && !$isUploader) return $this->json(['error' => 'Only the uploader or an administrator may delete this document.'], JsonResponse::HTTP_FORBIDDEN);
        $this->entityManager->remove($document);
        $this->entityManager->flush();
        $this->documentStorage->delete($document->getStoredName());
        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    private function parseDate(string $value): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) throw new \InvalidArgumentException('Invalid calendar date.');
        return $date;
    }

    private function parseOptionalId(?string $value): array
    {
        if ($value === null || $value === '') return ['id' => null];
        if (!ctype_digit($value) || (int) $value < 1) return ['error' => 'Project id must be a positive integer.'];
        return ['id' => (int) $value];
    }

    private function serializeTimeEntry(TimeEntry $entry): array
    {
        return ['id' => $entry->getId(), 'project_id' => $entry->getProject()->getId(), 'project_title' => $entry->getProject()->getTitle(), 'task_id' => $entry->getTask()?->getId(), 'task_title' => $entry->getTask()?->getTitle(), 'user_id' => $entry->getUser()->getId(), 'user_email' => $entry->getUser()->getUserIdentifier(), 'work_date' => $entry->getWorkDate()->format('Y-m-d'), 'duration_minutes' => $entry->getDurationMinutes(), 'billable' => $entry->isBillable(), 'description' => $entry->getDescription()];
    }

    private function serializeExpense(Expense $expense, bool $withApprovals): array
    {
        $serialized = ['id' => $expense->getId(), 'project_id' => $expense->getProject()->getId(), 'project_title' => $expense->getProject()->getTitle(), 'submitter_id' => $expense->getSubmitter()->getId(), 'submitter_email' => $expense->getSubmitter()->getUserIdentifier(), 'description' => $expense->getDescription(), 'amount' => $this->money->formatMinor($expense->getAmountMinor()), 'currency' => $expense->getCurrency(), 'expense_date' => $expense->getExpenseDate()->format('Y-m-d'), 'status' => $expense->getStatus()];
        if ($withApprovals) $serialized['approvals'] = array_map(fn (ExpenseApproval $approval): array => ['id' => $approval->getId(), 'decision' => $approval->getDecision(), 'actor_email' => $approval->getActor()->getUserIdentifier(), 'comment' => $approval->getComment(), 'created_at' => $approval->getCreatedAt()->format(\DateTimeInterface::ATOM)], $this->approvalRepository->findForExpense((int) $expense->getId()));
        return $serialized;
    }

    private function serializeDocument(ProjectDocument $document): array
    {
        return ['id' => $document->getId(), 'project_id' => $document->getProject()->getId(), 'original_name' => $document->getOriginalName(), 'mime_type' => $document->getMimeType(), 'size_bytes' => $document->getSizeBytes(), 'uploaded_by' => $document->getUploadedBy()?->getUserIdentifier(), 'created_at' => $document->getCreatedAt()->format(\DateTimeInterface::ATOM), 'download_url' => '/api/operations/documents/'.$document->getId().'/download'];
    }
}