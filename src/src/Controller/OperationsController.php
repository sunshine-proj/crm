<?php

namespace App\Controller;

use App\Dto\ExpenseDecisionInput;
use App\Dto\ExpenseInput;
use App\Dto\ExpenseReimbursementInput;
use App\Dto\HourlyCostRateInput;
use App\Dto\TimeEntryDecisionInput;
use App\Dto\TimeEntryReopenInput;
use App\Entity\TimeEntryEvent;
use App\Repository\TimeEntryEventRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use App\Dto\TimeEntryBatchInput;
use App\Dto\TimeEntryInput;
use App\Service\Time\TimeEntryFilter;
use App\Service\Time\TimeEntryRecorder;
use App\Service\Time\TimeEntryRejected;
use App\Service\Time\TimeVisibility;
use App\Service\Module\ModuleGate;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use App\Entity\Expense;
use App\Entity\ExpenseApproval;
use App\Entity\ExpenseReimbursement;
use App\Entity\Project;
use App\Entity\ProjectDocument;
use App\Entity\Task;
use App\Entity\TimeEntry;
use App\Entity\User;
use App\Repository\ExpenseApprovalRepository;
use App\Repository\ExpenseRepository;
use App\Repository\ExpenseReimbursementRepository;
use App\Repository\ProjectDocumentRepository;
use App\Repository\ProjectRepository;
use App\Repository\TimeEntryRepository;
use App\Repository\UserRepository;
use App\Service\FinanceCatalog;
use App\Service\InvoiceMoney;
use App\Service\ProjectDocumentStorage;
use App\Service\FinancePostingService;
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
        private readonly TimeEntryRepository $timeEntryRepository,
        private readonly UserRepository $userRepository,
        private readonly ExpenseRepository $expenseRepository,
        private readonly ExpenseReimbursementRepository $reimbursementRepository,
        private readonly ExpenseApprovalRepository $approvalRepository,
        private readonly ProjectDocumentRepository $documentRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly InvoiceMoney $money,
        private readonly FinanceCatalog $financeCatalog,
        private readonly ProjectDocumentStorage $documentStorage,
        private readonly FinancePostingService $postingService,
        private readonly TimeEntryEventRepository $timeEntryEventRepository,
        private readonly TimeEntryRecorder $timeEntryRecorder,
        private readonly TimeVisibility $timeVisibility,
        private readonly ValidatorInterface $validator,
        private readonly ModuleGate $modules,
        #[Autowire(service: 'monolog.logger.audit')] private readonly LoggerInterface $auditLogger,
    ) {
    }

    #[Route('/time-entries', name: 'time_entries_list', methods: ['GET'])]
    public function timeEntries(Request $request): JsonResponse
    {
        $actor = $this->getUser();
        if (!$actor instanceof User) return $this->json(['error' => 'Authenticated user is unavailable.'], JsonResponse::HTTP_UNAUTHORIZED);
        try {
            $filter = TimeEntryFilter::fromQuery($request->query);
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['error' => $exception->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
        }
        if ($request->query->get('mine') === '1') $filter = $filter->forUser((int) $actor->getId());
        if ($filter->projectId === null && $filter->userId === null) return $this->json(['error' => 'Choose a project or an employee to list time entries.'], JsonResponse::HTTP_BAD_REQUEST);
        if ($filter->projectId !== null && $this->projectRepository->find($filter->projectId) === null) return $this->json(['error' => 'Project not found.'], JsonResponse::HTTP_NOT_FOUND);
        // Entries outside the caller's scope are left out rather than refused, so a project page still lists one's own time.
        $entries = $this->timeEntryRepository->findVisible($this->timeVisibility->scopeFor($actor), $filter);

        return $this->json(array_map(fn (TimeEntry $entry): array => $this->serializeTimeEntry($entry), $entries));
    }

    #[Route('/time-entries', name: 'time_entries_create', methods: ['POST'])]
    public function createTimeEntry(#[MapRequestPayload] TimeEntryInput $input): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) return $this->json(['error' => 'Authenticated user is unavailable.'], JsonResponse::HTTP_UNAUTHORIZED);
        try {
            $entry = $this->timeEntryRecorder->build($input, $user);
        } catch (TimeEntryRejected $rejected) {
            return $this->json(['error' => $rejected->getMessage()], $rejected->status());
        }
        $this->entityManager->persist($entry);
        $this->entityManager->flush();
        return $this->json($this->serializeTimeEntry($entry), JsonResponse::HTTP_CREATED);
    }

    /** Weekly timesheet submit: every entry is validated first and all are saved together, or none is. */
    #[Route('/time-entries/batch', name: 'time_entries_batch', methods: ['POST'])]
    public function createTimeEntries(#[MapRequestPayload] TimeEntryBatchInput $input): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) return $this->json(['error' => 'Authenticated user is unavailable.'], JsonResponse::HTTP_UNAUTHORIZED);
        $entries = [];
        foreach ($input->entries as $index => $raw) {
            $label = sprintf('Entry %d: ', $index + 1);
            $item = is_array($raw) ? TimeEntryInput::fromArray($raw) : null;
            if ($item === null) return $this->json(['error' => $label.'fields have the wrong type.'], JsonResponse::HTTP_BAD_REQUEST);
            $violations = $this->validator->validate($item);
            if (count($violations) > 0) return $this->json(['error' => $label.$violations[0]->getPropertyPath().': '.$violations[0]->getMessage()], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
            try {
                $entries[] = $this->timeEntryRecorder->build($item, $user);
            } catch (TimeEntryRejected $rejected) {
                return $this->json(['error' => $label.$rejected->getMessage()], $rejected->status());
            }
        }
        foreach ($entries as $entry) {
            $this->entityManager->persist($entry);
        }
        $this->entityManager->flush();

        return $this->json(array_map(fn (TimeEntry $entry): array => $this->serializeTimeEntry($entry), $entries), JsonResponse::HTTP_CREATED);
    }

    #[Route('/time-entries/{id<\d+>}/decision', name: 'time_entry_decision', methods: ['POST'])]
    public function decideTimeEntry(int $id, #[MapRequestPayload] TimeEntryDecisionInput $input): JsonResponse
    {
        $entry = $this->timeEntryRepository->find($id);
        if (!$entry instanceof TimeEntry) return $this->json(['error' => 'Time entry not found.'], JsonResponse::HTTP_NOT_FOUND);
        $actor = $this->getUser();
        if (!$actor instanceof User) return $this->json(['error' => 'Authenticated user is unavailable.'], JsonResponse::HTTP_UNAUTHORIZED);
        $isAdmin = in_array('ROLE_ADMIN', $actor->getRoles(), true);
        $isManager = $entry->getProject()->getManager()?->getId() === $actor->getId();
        if (!$isAdmin && !$isManager) return $this->json(['error' => 'Only an administrator or the project manager may decide this time entry.'], JsonResponse::HTTP_FORBIDDEN);
        if ($input->decision === null || !in_array($input->decision, ['approved', 'rejected'], true)) return $this->json(['error' => 'Decision must be approved or rejected.'], JsonResponse::HTTP_BAD_REQUEST);
        if ($entry->getUser()->getId() === $actor->getId() && !$isAdmin) return $this->json(['error' => 'Time-entry submitters cannot approve their own entries.'], JsonResponse::HTTP_FORBIDDEN);
        try {
            $comment = $input->comment !== null ? trim($input->comment) : null;
            $entry->decide($input->decision, $actor, $comment);
            $this->entityManager->persist(new TimeEntryEvent($entry, $actor, $input->decision, $comment));
        } catch (\DomainException $exception) {
            return $this->json(['error' => $exception->getMessage()], JsonResponse::HTTP_CONFLICT);
        }
        $this->entityManager->flush();

        return $this->json($this->serializeTimeEntry($entry));
    }

    #[Route('/time-entries/{id<\d+>}/reopen', name: 'time_entry_reopen', methods: ['POST'])]
    public function reopenTimeEntry(int $id, #[MapRequestPayload] TimeEntryReopenInput $input): JsonResponse
    {
        $entry = $this->timeEntryRepository->find($id);
        if (!$entry instanceof TimeEntry) return $this->json(['error' => 'Time entry not found.'], JsonResponse::HTTP_NOT_FOUND);
        $actor = $this->getUser();
        if (!$actor instanceof User) return $this->json(['error' => 'Authenticated user is unavailable.'], JsonResponse::HTTP_UNAUTHORIZED);
        $isAdmin = $this->isGranted('ROLE_ADMIN');
        if (!$isAdmin && $entry->getProject()->getManager()?->getId() !== $actor->getId()) return $this->json(['error' => 'Only an administrator or the project manager may reopen approved time.'], JsonResponse::HTTP_FORBIDDEN);
        $reason = trim((string) $input->reason);
        if ($reason === '') return $this->json(['error' => 'A reason is required to reopen approved time.'], JsonResponse::HTTP_BAD_REQUEST);
        try {
            $entry->reopen();
        } catch (\DomainException $exception) {
            return $this->json(['error' => $exception->getMessage()], JsonResponse::HTTP_CONFLICT);
        }
        $this->entityManager->persist(new TimeEntryEvent($entry, $actor, TimeEntryEvent::REOPENED, $reason));
        $this->entityManager->flush();
        $this->auditLogger->info('Approved time entry reopened.', ['time_entry_id' => $id, 'actor' => $actor->getUserIdentifier()]);

        return $this->json($this->serializeTimeEntry($entry));
    }

    #[Route('/time-entries/{id<\d+>}', name: 'time_entry_withdraw', methods: ['DELETE'])]
    public function withdrawTimeEntry(int $id): Response
    {
        $entry = $this->timeEntryRepository->find($id);
        if (!$entry instanceof TimeEntry) return $this->json(['error' => 'Time entry not found.'], JsonResponse::HTTP_NOT_FOUND);
        $actor = $this->getUser();
        if (!$actor instanceof User) return $this->json(['error' => 'Authenticated user is unavailable.'], JsonResponse::HTTP_UNAUTHORIZED);
        if ($entry->getUser()->getId() !== $actor->getId() && !$this->isGranted('ROLE_ADMIN')) return $this->json(['error' => 'Only the author or an administrator may withdraw this time entry.'], JsonResponse::HTTP_FORBIDDEN);
        if (!$entry->isWithdrawable()) return $this->json(['error' => 'Only pending or rejected time that has not been invoiced can be withdrawn; reopen approved time first.'], JsonResponse::HTTP_CONFLICT);
        $this->auditLogger->info('Time entry withdrawn.', ['time_entry_id' => $id, 'project_id' => $entry->getProject()->getId(), 'actor' => $actor->getUserIdentifier(), 'status' => $entry->getApprovalStatus()]);
        $this->entityManager->remove($entry);
        $this->entityManager->flush();

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/time-entries/{id<\d+>}/history', name: 'time_entry_history', methods: ['GET'])]
    public function timeEntryHistory(int $id): JsonResponse
    {
        $entry = $this->timeEntryRepository->find($id);
        $actor = $this->getUser();
        if (!$entry instanceof TimeEntry || !$actor instanceof User || !$this->timeVisibility->scopeFor($actor)->canSee($entry)) return $this->json(['error' => 'Time entry not found.'], JsonResponse::HTTP_NOT_FOUND);

        return $this->json(array_map(static fn (TimeEntryEvent $event): array => [
            'id' => $event->getId(), 'action' => $event->getAction(), 'actor' => $event->getActor()->getUserIdentifier(),
            'comment' => $event->getComment(), 'created_at' => $event->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ], $this->timeEntryEventRepository->findForEntry($id)));
    }

    #[Route('/users/{id<\d+>}/cost-rate', name: 'user_cost_rate', methods: ['PUT'])]
    #[IsGranted('ROLE_ADMIN')]
    public function updateUserCostRate(int $id, #[MapRequestPayload] HourlyCostRateInput $input): JsonResponse
    {
        $user = $this->userRepository->find($id);
        if (!$user instanceof User) return $this->json(['error' => 'User not found.'], JsonResponse::HTTP_NOT_FOUND);
        if ($input->hourly_rate === null && $input->currency === null) {
            $user->setHourlyCostRate(null, null);
        } elseif ($input->hourly_rate === null || $input->currency === null || !$this->financeCatalog->supportsCurrency($input->currency)) {
            return $this->json(['error' => 'Provide a non-negative hourly rate and supported currency together.'], JsonResponse::HTTP_BAD_REQUEST);
        } else {
            $rate = $this->money->parseMinor($input->hourly_rate);
            if ($rate === null) return $this->json(['error' => 'Hourly cost rate must be non-negative with at most two decimal places.'], JsonResponse::HTTP_BAD_REQUEST);
            $user->setHourlyCostRate($rate, $input->currency);
        }
        $this->entityManager->flush();

        return $this->json([
            'user_id' => $user->getId(),
            'email' => $user->getUserIdentifier(),
            'hourly_cost_rate' => $user->getHourlyCostRateMinor() !== null ? $this->money->formatMinor($user->getHourlyCostRateMinor()) : null,
            'currency' => $user->getHourlyCostCurrency(),
        ]);
    }

    #[Route('/projects/{id<\d+>}/profitability', name: 'project_profitability', methods: ['GET'])]
    #[IsGranted('ROLE_FINANCE')]
    public function projectProfitability(int $id, Request $request): JsonResponse
    {
        $project = $this->projectRepository->find($id);
        if (!$project instanceof Project) return $this->json(['error' => 'Project not found.'], JsonResponse::HTTP_NOT_FOUND);
        $currency = $request->query->get('currency');
        if (!is_string($currency) || !$this->financeCatalog->supportsCurrency($currency)) {
            return $this->json(['error' => 'Choose a supported currency: EUR, GBP, or USD.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        try {
            $from = ($raw = $request->query->get('from')) !== null && $raw !== '' ? $this->parseDate((string) $raw) : null;
            $to = ($raw = $request->query->get('to')) !== null && $raw !== '' ? $this->parseDate((string) $raw) : null;
        } catch (\InvalidArgumentException) {
            return $this->json(['error' => 'Period dates must use YYYY-MM-DD.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        if ($from !== null && $to !== null && $from > $to) return $this->json(['error' => 'Period start must not be after the period end.'], JsonResponse::HTTP_BAD_REQUEST);
        $inPeriod = static fn (\DateTimeImmutable $date): bool => ($from === null || $date >= $from) && ($to === null || $date <= $to);
        $revenue = 0;
        $laborCost = 0;
        $approvedMinutes = 0;
        $pendingMinutes = 0;
        $billableUnpriced = 0;
        $uncostedApprovedTime = 0;
        foreach ($this->timeEntryRepository->findForProject($id) as $entry) {
            if (!$inPeriod($entry->getWorkDate())) continue;
            if ($entry->getApprovalStatus() === 'pending_approval') $pendingMinutes += $entry->getDurationMinutes();
            if ($entry->getApprovalStatus() !== 'approved') continue;
            $approvedMinutes += $entry->getDurationMinutes();
            if ($entry->isBillable()) {
                if ($entry->getBillingCurrency() === $currency && $entry->getBillingAmountMinor() !== null) {
                    $revenue += $entry->getBillingAmountMinor();
                } else {
                    ++$billableUnpriced;
                }
            }
            if ($entry->getCostCurrency() === $currency && $entry->getCostMinor() !== null) {
                $laborCost += $entry->getCostMinor();
            } elseif ($entry->getCostCurrency() === null || $entry->getCostMinor() === null) {
                ++$uncostedApprovedTime;
            }
        }
        $expenseCost = 0;
        $excludedExpenses = 0;
        foreach ($this->expenseRepository->findFiltered($id, 'approved') as $expense) {
            if (!$inPeriod($expense->getExpenseDate())) continue;
            if ($expense->getCurrency() === $currency) {
                $expenseCost += $expense->getAmountMinor();
            } else {
                ++$excludedExpenses;
            }
        }
        $cost = $laborCost + $expenseCost;
        $margin = $revenue - $cost;
        $budget = $project->getBudgetCurrency() === $currency ? $project->getBudgetMinor() : null;
        $warningPercent = (int) $this->modules->setting('project_operations', 'budget_warning_percent');

        return $this->json([
            'project_id' => $id,
            'currency' => $currency,
            'period' => ['from' => $from?->format('Y-m-d'), 'to' => $to?->format('Y-m-d')],
            'approved_minutes' => $approvedMinutes,
            'pending_approval_minutes' => $pendingMinutes,
            'approved_billable_revenue' => $this->money->formatMinor($revenue),
            'approved_labor_cost' => $this->money->formatMinor($laborCost),
            'approved_expense_cost' => $this->money->formatMinor($expenseCost),
            'total_cost' => $this->money->formatMinor($cost),
            'gross_margin' => $this->money->formatMinor($margin),
            'budget' => $budget !== null ? $this->money->formatMinor($budget) : null,
            'budget_variance' => $budget !== null && $from === null && $to === null ? $this->money->formatMinor($budget - $cost) : null,
            'budget_consumed_percent' => $budget !== null && $budget > 0 && $from === null && $to === null ? intdiv($cost * 100, $budget) : null,
            'budget_warning' => $budget !== null && $from === null && $to === null && $cost * 100 >= $budget * $warningPercent,
            'budget_warning_percent' => $warningPercent,
            'unpriced_billable_entries' => $billableUnpriced,
            'uncosted_approved_time_entries' => $uncostedApprovedTime,
            'excluded_expenses_other_currency' => $excludedExpenses,
            'basis' => 'Operational totals from approved time-entry rate snapshots (by work date) and approved expenses (by expense date) within the optional period; the budget is the whole-project budget, not prorated. Cross-currency conversion, payroll, tax, and statutory accounting are excluded.',
        ]);
    }

    #[Route('/expenses', name: 'expenses_list', methods: ['GET'])]
    public function expenses(Request $request): JsonResponse
    {
        $status = $request->query->get('status');
        if ($status !== null && !in_array($status, ['pending_approval', 'approved', 'rejected'], true)) return $this->json(['error' => 'Invalid expense status filter.'], JsonResponse::HTTP_BAD_REQUEST);
        $projectId = $this->parseOptionalId($request->query->get('project_id'));
        if (isset($projectId['error'])) return $this->json(['error' => $projectId['error']], JsonResponse::HTTP_BAD_REQUEST);
        $currentUser = $this->getUser();
        $isAdmin = $currentUser instanceof User && $this->isGranted('ROLE_FINANCE');
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
        $category = $input->category ?? 'other';
        if (!in_array($category, ['travel', 'accommodation', 'meals', 'software', 'supplies', 'other'], true)) return $this->json(['error' => 'Choose a supported expense category.'], JsonResponse::HTTP_BAD_REQUEST);
        $expense = new Expense($project, $user, trim($input->description), $amount, $input->currency, $expenseDate, $category);
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
        if ($input->decision === 'approved') {
            try {
                $this->postingService->assertCanPost($expense->getExpenseDate(), ['project_expense', 'expense_payable']);
            } catch (\DomainException $exception) {
                return $this->json(['error' => $exception->getMessage()], JsonResponse::HTTP_CONFLICT);
            }
        }
        try {
            if ($input->decision === 'approved') {
                $this->postingService->runAtomically(function () use ($expense, $actor, $input): void {
                    $expense->decide($input->decision);
                    $approval = new ExpenseApproval($expense, $actor, $input->decision, $input->comment !== null ? trim($input->comment) : null);
                    $this->entityManager->persist($approval);
                    $this->postingService->post('expense_approval', (int) $expense->getId(), $expense->getExpenseDate(), $expense->getCurrency(), 'Approved expense '.$expense->getId(), [
                        ['role' => 'project_expense', 'debit_minor' => $expense->getAmountMinor(), 'credit_minor' => 0, 'memo' => $expense->getCategory().' expense'],
                        ['role' => 'expense_payable', 'debit_minor' => 0, 'credit_minor' => $expense->getAmountMinor(), 'memo' => 'Expense reimbursement payable'],
                    ]);
                });
            } else {
                $expense->decide($input->decision);
                $approval = new ExpenseApproval($expense, $actor, $input->decision, $input->comment !== null ? trim($input->comment) : null);
                $this->entityManager->persist($approval);
                $this->entityManager->flush();
            }
        } catch (\DomainException $exception) {
            return $this->json(['error' => $exception->getMessage()], JsonResponse::HTTP_CONFLICT);
        }
        return $this->json($this->serializeExpense($expense, true));
    }

    #[Route('/expenses/{id<\d+>}/reimbursements', name: 'expense_reimbursements_list', methods: ['GET'])]
    public function expenseReimbursements(int $id): JsonResponse
    {
        $expense = $this->expenseRepository->find($id);
        if ($expense === null) return $this->json(['error' => 'Expense not found.'], JsonResponse::HTTP_NOT_FOUND);
        $actor = $this->getUser();
        if (!$actor instanceof User) return $this->json(['error' => 'Authenticated user is unavailable.'], JsonResponse::HTTP_UNAUTHORIZED);
        $isAdmin = $this->isGranted('ROLE_FINANCE');
        $isSubmitter = $expense->getSubmitter()->getId() === $actor->getId();
        $isProjectManager = $expense->getProject()->getManager()?->getId() === $actor->getId();
        if (!$isAdmin && !$isSubmitter && !$isProjectManager) {
            return $this->json(['error' => 'Only an administrator, finance user, the expense submitter, or the project manager may view reimbursements.'], JsonResponse::HTTP_FORBIDDEN);
        }

        return $this->json(array_map(fn (ExpenseReimbursement $item): array => $this->serializeReimbursement($item), $this->reimbursementRepository->findForExpense($id)));
    }

    #[Route('/expenses/{id<\d+>}/reimbursements', name: 'expense_reimbursements_create', methods: ['POST'])]
    #[IsGranted('ROLE_FINANCE')]
    public function reimburseExpense(int $id, #[MapRequestPayload] ExpenseReimbursementInput $input): JsonResponse
    {
        $expense = $this->expenseRepository->find($id);
        if ($expense === null) return $this->json(['error' => 'Expense not found.'], JsonResponse::HTTP_NOT_FOUND);
        if ($expense->getStatus() !== 'approved' || $input->amount === null || $input->paid_at === null) {
            return $this->json(['error' => 'An approved expense, payment amount, and payment date are required.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        $amount = $this->money->parseMinor($input->amount);
        if ($amount === null || $amount < 1) return $this->json(['error' => 'Reimbursement amount must be positive with at most two decimal places.'], JsonResponse::HTTP_BAD_REQUEST);
        try { $paidAt = $this->parseDate($input->paid_at); }
        catch (\InvalidArgumentException) { return $this->json(['error' => 'Payment date must use YYYY-MM-DD.'], JsonResponse::HTTP_BAD_REQUEST); }
        $paid = $this->reimbursementRepository->sumMinorForExpense($id);
        if ($amount > $expense->getAmountMinor() - $paid) return $this->json(['error' => 'Reimbursement exceeds the outstanding expense balance.'], JsonResponse::HTTP_CONFLICT);
        try {
            $this->postingService->assertCanPost($paidAt, ['expense_payable', 'cash']);
        } catch (\DomainException $exception) {
            return $this->json(['error' => $exception->getMessage()], JsonResponse::HTTP_CONFLICT);
        }
        $actor = $this->getUser();
        if (!$actor instanceof User) return $this->json(['error' => 'Authenticated user is unavailable.'], JsonResponse::HTTP_UNAUTHORIZED);
        $reimbursement = new ExpenseReimbursement($expense, $actor, $amount, $paidAt, $input->reference !== null ? trim($input->reference) : null);
        $this->postingService->runAtomically(function () use ($reimbursement, $paidAt, $expense, $amount): void {
            $this->entityManager->persist($reimbursement);
            $this->entityManager->flush();
            $this->postingService->post('expense_reimbursement', (int) $reimbursement->getId(), $paidAt, $expense->getCurrency(), 'Expense reimbursement '.$expense->getId(), [
                ['role' => 'expense_payable', 'debit_minor' => $amount, 'credit_minor' => 0, 'memo' => 'Expense payable settlement'],
                ['role' => 'cash', 'debit_minor' => 0, 'credit_minor' => $amount, 'memo' => 'Expense reimbursement'],
            ]);
        });
        return $this->json($this->serializeReimbursement($reimbursement), JsonResponse::HTTP_CREATED);
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
        $canSeeCost = $this->isGranted('ROLE_FINANCE');

        return ['id' => $entry->getId(), 'project_id' => $entry->getProject()->getId(), 'project_title' => $entry->getProject()->getTitle(), 'task_id' => $entry->getTask()?->getId(), 'task_title' => $entry->getTask()?->getTitle(), 'user_id' => $entry->getUser()->getId(), 'user_email' => $entry->getUser()->getUserIdentifier(), 'work_date' => $entry->getWorkDate()->format('Y-m-d'), 'duration_minutes' => $entry->getDurationMinutes(), 'billable' => $entry->isBillable(), 'approval_status' => $entry->getApprovalStatus(), 'decided_by' => $entry->getDecidedBy()?->getUserIdentifier(), 'decided_at' => $entry->getDecidedAt()?->format(\DateTimeInterface::ATOM), 'decision_comment' => $entry->getDecisionComment(), 'cost_amount' => $canSeeCost && $entry->getCostMinor() !== null ? $this->money->formatMinor($entry->getCostMinor()) : null, 'cost_currency' => $canSeeCost ? $entry->getCostCurrency() : null, 'billing_amount' => $entry->getBillingAmountMinor() !== null ? $this->money->formatMinor($entry->getBillingAmountMinor()) : null, 'billing_currency' => $entry->getBillingCurrency(), 'invoice_id' => $entry->getInvoice()?->getId(), 'description' => $entry->getDescription()];
    }

    private function serializeExpense(Expense $expense, bool $withApprovals): array
    {
        $reimbursed = $this->reimbursementRepository->sumMinorForExpense((int) $expense->getId());
        $serialized = ['id' => $expense->getId(), 'project_id' => $expense->getProject()->getId(), 'project_title' => $expense->getProject()->getTitle(), 'submitter_id' => $expense->getSubmitter()->getId(), 'submitter_email' => $expense->getSubmitter()->getUserIdentifier(), 'description' => $expense->getDescription(), 'category' => $expense->getCategory(), 'amount' => $this->money->formatMinor($expense->getAmountMinor()), 'reimbursed' => $this->money->formatMinor($reimbursed), 'balance_to_reimburse' => $this->money->formatMinor(max(0, $expense->getAmountMinor() - $reimbursed)), 'currency' => $expense->getCurrency(), 'expense_date' => $expense->getExpenseDate()->format('Y-m-d'), 'status' => $expense->getStatus()];
        if ($withApprovals) $serialized['approvals'] = array_map(fn (ExpenseApproval $approval): array => ['id' => $approval->getId(), 'decision' => $approval->getDecision(), 'actor_email' => $approval->getActor()->getUserIdentifier(), 'comment' => $approval->getComment(), 'created_at' => $approval->getCreatedAt()->format(\DateTimeInterface::ATOM)], $this->approvalRepository->findForExpense((int) $expense->getId()));
        return $serialized;
    }

    private function serializeDocument(ProjectDocument $document): array
    {
        return ['id' => $document->getId(), 'project_id' => $document->getProject()->getId(), 'original_name' => $document->getOriginalName(), 'mime_type' => $document->getMimeType(), 'size_bytes' => $document->getSizeBytes(), 'uploaded_by' => $document->getUploadedBy()?->getUserIdentifier(), 'created_at' => $document->getCreatedAt()->format(\DateTimeInterface::ATOM), 'download_url' => '/api/operations/documents/'.$document->getId().'/download'];
    }

    private function serializeReimbursement(ExpenseReimbursement $item): array
    {
        return ['id' => $item->getId(), 'expense_id' => $item->getExpense()->getId(), 'amount' => $this->money->formatMinor($item->getAmountMinor()), 'currency' => $item->getExpense()->getCurrency(), 'paid_at' => $item->getPaidAt()->format('Y-m-d'), 'reference' => $item->getReference(), 'recorded_by' => $item->getRecordedBy()->getUserIdentifier()];
    }
}