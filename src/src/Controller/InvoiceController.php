<?php

namespace App\Controller;

use App\Dto\InvoiceInput;
use App\Dto\ApprovedTimeInvoiceInput;
use App\Dto\InvoiceFromMilestoneInput;
use App\Entity\Invoice;
use App\Entity\Project;
use App\Entity\Task;
use App\Entity\User;
use App\Entity\TimeEntry;
use App\Entity\ProjectMilestone;
use App\Repository\InvoiceRepository;
use App\Repository\PaymentRepository;
use App\Repository\ProjectRepository;
use App\Repository\TaskRepository;
use App\Repository\UserRepository;
use App\Repository\TimeEntryRepository;
use App\Repository\ProjectMilestoneRepository;
use App\Service\InvoiceMoney;
use App\Service\InvoicePdfService;
use App\Service\FinancePostingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/invoices', name: 'api_invoices_')]
#[IsGranted('ROLE_USER')]
final class InvoiceController extends AbstractController
{
	public function __construct(
		private readonly InvoiceRepository $invoiceRepository,
		private readonly TimeEntryRepository $timeEntryRepository,
		private readonly ProjectMilestoneRepository $milestoneRepository,
		private readonly PaymentRepository $paymentRepository,
		private readonly ProjectRepository $projectRepository,
		private readonly TaskRepository $taskRepository,
		private readonly UserRepository $userRepository,
		private readonly EntityManagerInterface $entityManager,
		private readonly InvoiceMoney $money,
		private readonly InvoicePdfService $pdfService,
		private readonly FinancePostingService $postingService,
	) {
	}

	#[Route('', name: 'list', methods: ['GET'])]
	public function list(Request $request): JsonResponse
	{
		$filters = $this->parseFilters($request);
		if (isset($filters['error'])) {
			return $this->json(['error' => $filters['error']], JsonResponse::HTTP_BAD_REQUEST);
		}

		$invoices = $this->invoiceRepository->findFiltered($filters);

		return $this->json(array_map(fn (Invoice $invoice): array => $this->serializeInvoice($invoice), $invoices));
	}

	#[Route('', name: 'create', methods: ['POST'])]
	public function create(#[MapRequestPayload] InvoiceInput $input): JsonResponse
	{
		$invoice = new Invoice();
		$error = $this->applyInput($invoice, $input);
		if ($error !== null) {
			return $this->json(['error' => $error], JsonResponse::HTTP_BAD_REQUEST);
		}

		$this->entityManager->persist($invoice);
		$this->entityManager->flush();
		$invoice->setInvoiceNumber(sprintf('INV-%s-%06d', $invoice->getInvoiceDate()->format('Y'), $invoice->getId()));
		$this->entityManager->flush();

		return $this->json($this->serializeInvoice($invoice), JsonResponse::HTTP_CREATED);
	}

		#[Route('/from-approved-time', name: 'from_approved_time', methods: ['POST'])]
		public function createFromApprovedTime(#[MapRequestPayload] ApprovedTimeInvoiceInput $input): JsonResponse
		{
			try {
				$invoiceDate = $input->invoice_date !== null ? $this->parseDate($input->invoice_date) : null;
				$dueDate = $input->due_date !== null ? $this->parseDate($input->due_date) : null;
			} catch (\InvalidArgumentException) {
				return $this->json(['error' => 'Invoice dates must use YYYY-MM-DD.'], JsonResponse::HTTP_BAD_REQUEST);
			}
			if ($invoiceDate === null || ($dueDate !== null && $dueDate < $invoiceDate)) {
				return $this->json(['error' => 'A valid invoice date and a due date on or after it are required.'], JsonResponse::HTTP_BAD_REQUEST);
			}
			foreach ($input->entry_ids as $entryId) {
				if (!is_int($entryId) || $entryId < 1) {
					return $this->json(['error' => 'Time-entry ids must be positive integers.'], JsonResponse::HTTP_BAD_REQUEST);
				}
			}
			$entryIds = array_values(array_unique($input->entry_ids));
			if ($entryIds === [] || count($entryIds) !== count($input->entry_ids)) {
				return $this->json(['error' => 'Choose one or more distinct approved time entries.'], JsonResponse::HTTP_BAD_REQUEST);
			}
			$entries = [];
			$project = null;
			$total = 0;
			$items = [];
			foreach ($entryIds as $entryId) {
				$entry = $this->timeEntryRepository->find($entryId);
				if (!$entry instanceof TimeEntry || $entry->getApprovalStatus() !== 'approved' || !$entry->isBillable() || $entry->getInvoice() !== null || $entry->getBillingAmountMinor() === null || $entry->getBillingCurrency() === null) {
					return $this->json(['error' => sprintf('Time entry %d must be approved, billable, priced, and not already invoiced.', $entryId)], JsonResponse::HTTP_CONFLICT);
				}
				if ($project === null) {
					$project = $entry->getProject();
				} elseif ($project->getId() !== $entry->getProject()->getId()) {
					return $this->json(['error' => 'All selected time entries must belong to one project.'], JsonResponse::HTTP_BAD_REQUEST);
				}
				if ($total > PHP_INT_MAX - $entry->getBillingAmountMinor()) {
					return $this->json(['error' => 'Invoice total exceeds the supported amount range.'], JsonResponse::HTTP_BAD_REQUEST);
				}
				$total += $entry->getBillingAmountMinor();
				$description = $entry->getTask() !== null ? (string) $entry->getTask()->getTitle() : $entry->getDescription();
				$items[] = [
					'task_id' => null,
					'description' => mb_substr('Approved time: '.$description, 0, 255),
					'amount' => $this->money->formatMinor($entry->getBillingAmountMinor()),
				];
				$entries[] = $entry;
			}
			if (!$project instanceof Project || $project->getClient() === null || $total < 1) {
				return $this->json(['error' => 'The selected project must have a client and positive billable time total.'], JsonResponse::HTTP_CONFLICT);
			}
			$currency = $entries[0]->getBillingCurrency();
			foreach ($entries as $entry) {
				if ($entry->getBillingCurrency() !== $currency) {
					return $this->json(['error' => 'All selected time entries must have the same billing currency.'], JsonResponse::HTTP_BAD_REQUEST);
				}
			}

			$invoice = new Invoice();
			$invoice->setDetails(
				$project->getClient(),
				$project,
				$project->getManager(),
				(string) $project->getClient()->getTitle(),
				(string) $project->getTitle(),
				$project->getManager()?->getUserIdentifier(),
				$invoiceDate,
				$dueDate,
				$currency,
				$items,
				$total,
			);
			$this->postingService->runAtomically(function () use ($invoice, $invoiceDate, $entries): void {
				$this->entityManager->persist($invoice);
				$this->entityManager->flush();
				$invoice->setInvoiceNumber(sprintf('INV-%s-%06d', $invoiceDate->format('Y'), $invoice->getId()));
				foreach ($entries as $entry) {
					$entry->setInvoice($invoice);
				}
				$this->entityManager->flush();
			});

			return $this->json($this->serializeInvoice($invoice), JsonResponse::HTTP_CREATED);
		}

		#[Route('/from-milestone/{id<\d+>}', name: 'from_milestone', methods: ['POST'])]
		public function createFromMilestone(int $id, #[MapRequestPayload] InvoiceFromMilestoneInput $input): JsonResponse
		{
			$milestone = $this->milestoneRepository->find($id);
			if (!$milestone instanceof ProjectMilestone) {
				return $this->json(['error' => 'Milestone not found.'], JsonResponse::HTTP_NOT_FOUND);
			}
			try {
				$invoiceDate = $input->invoice_date !== null ? $this->parseDate($input->invoice_date) : null;
				$dueDate = $input->due_date !== null ? $this->parseDate($input->due_date) : null;
			} catch (\InvalidArgumentException) {
				return $this->json(['error' => 'Invoice dates must use YYYY-MM-DD.'], JsonResponse::HTTP_BAD_REQUEST);
			}
			if ($invoiceDate === null || ($dueDate !== null && $dueDate < $invoiceDate)) {
				return $this->json(['error' => 'A valid invoice date and a due date on or after it are required.'], JsonResponse::HTTP_BAD_REQUEST);
			}
			if ($milestone->getStatus() !== 'completed' || $milestone->getInvoice() !== null || $milestone->getAmountMinor() === null || $milestone->getCurrency() === null) {
				return $this->json(['error' => 'Only completed, priced milestones without an invoice may be invoiced.'], JsonResponse::HTTP_CONFLICT);
			}
			$project = $milestone->getProject();
			$client = $project->getClient();
			if ($client === null) {
				return $this->json(['error' => 'The milestone project must be linked to a client.'], JsonResponse::HTTP_CONFLICT);
			}
			$invoice = new Invoice();
			$invoice->setDetails(
				$client,
				$project,
				$project->getManager(),
				(string) $client->getTitle(),
				(string) $project->getTitle(),
				$project->getManager()?->getUserIdentifier(),
				$invoiceDate,
				$dueDate,
				$milestone->getCurrency(),
				[['task_id' => null, 'description' => mb_substr('Milestone: '.$milestone->getTitle(), 0, 255), 'amount' => $this->money->formatMinor($milestone->getAmountMinor())]],
				$milestone->getAmountMinor(),
			);
			$this->postingService->runAtomically(function () use ($invoice, $invoiceDate, $milestone): void {
				$this->entityManager->persist($invoice);
				$this->entityManager->flush();
				$invoice->setInvoiceNumber(sprintf('INV-%s-%06d', $invoiceDate->format('Y'), $invoice->getId()));
				$milestone->markInvoiced($invoice);
				$this->entityManager->flush();
			});

			return $this->json($this->serializeInvoice($invoice), JsonResponse::HTTP_CREATED);
		}

	#[Route('/{id<\d+>}', name: 'show', methods: ['GET'])]
	public function show(int $id): JsonResponse
	{
		$invoice = $this->invoiceRepository->find($id);
		if ($invoice === null) {
			return $this->json(['error' => 'Invoice not found.'], JsonResponse::HTTP_NOT_FOUND);
		}

		return $this->json($this->serializeInvoice($invoice));
	}

	#[Route('/{id<\d+>}', name: 'update', methods: ['PUT', 'PATCH'])]
	public function update(int $id, #[MapRequestPayload] InvoiceInput $input): JsonResponse
	{
		$invoice = $this->invoiceRepository->find($id);
		if ($invoice === null) {
			return $this->json(['error' => 'Invoice not found.'], JsonResponse::HTTP_NOT_FOUND);
		}
		if ($invoice->getStatus() !== 'draft' || $invoice->getSalesOrder() !== null) {
			return $this->json(['error' => 'Only draft invoices can be edited.'], JsonResponse::HTTP_CONFLICT);
		}
		$error = $this->applyInput($invoice, $input);
		if ($error !== null) {
			return $this->json(['error' => $error], JsonResponse::HTTP_BAD_REQUEST);
		}

		$this->entityManager->flush();

		return $this->json($this->serializeInvoice($invoice));
	}

	#[Route('/{id<\d+>}/issue', name: 'issue', methods: ['POST'])]
	public function issue(int $id): JsonResponse
	{
		$invoice = $this->invoiceRepository->find($id);
		if ($invoice === null) {
			return $this->json(['error' => 'Invoice not found.'], JsonResponse::HTTP_NOT_FOUND);
		}
		if ($invoice->getStatus() !== 'draft') {
			return $this->json(['error' => 'Only draft invoices can be issued.'], JsonResponse::HTTP_CONFLICT);
		}

		try {
			$this->postingService->assertCanPost($invoice->getInvoiceDate(), ['accounts_receivable', 'revenue']);
		} catch (\DomainException $exception) {
			return $this->json(['error' => $exception->getMessage()], JsonResponse::HTTP_CONFLICT);
		}
		$this->postingService->runAtomically(function () use ($invoice): void {
			$invoice->setStatus('issued');
			$this->postingService->post('invoice_issue', (int) $invoice->getId(), $invoice->getInvoiceDate(), $invoice->getCurrency(), 'Invoice '.$invoice->getInvoiceNumber(), [
				['role' => 'accounts_receivable', 'debit_minor' => $invoice->getTotalMinor(), 'credit_minor' => 0, 'memo' => 'Invoice receivable'],
				['role' => 'revenue', 'debit_minor' => 0, 'credit_minor' => $invoice->getTotalMinor(), 'memo' => 'Invoice revenue'],
			]);
		});

		return $this->json($this->serializeInvoice($invoice));
	}

	#[Route('/{id<\d+>}/pdf', name: 'pdf', methods: ['GET'])]
	public function pdf(int $id): Response
	{
		$invoice = $this->invoiceRepository->find($id);
		if ($invoice === null) {
			return $this->json(['error' => 'Invoice not found.'], JsonResponse::HTTP_NOT_FOUND);
		}
		return new Response($this->pdfService->render($invoice), Response::HTTP_OK, [
			'Content-Type' => 'application/pdf',
			'Content-Disposition' => 'attachment; filename="'.strtolower((string) $invoice->getInvoiceNumber()).'.pdf"',
		]);
	}

	#[Route('/{id<\d+>}', name: 'delete', methods: ['DELETE'])]
	public function delete(int $id): Response
	{
		$invoice = $this->invoiceRepository->find($id);
		if ($invoice === null) {
			return $this->json(['error' => 'Invoice not found.'], JsonResponse::HTTP_NOT_FOUND);
		}
		if ($invoice->getStatus() !== 'draft' || $invoice->getSalesOrder() !== null || $this->paymentRepository->count(['invoice' => $invoice]) > 0) {
			return $this->json(['error' => 'Only unpaid, project-linked draft invoices can be deleted.'], JsonResponse::HTTP_CONFLICT);
		}

		$this->entityManager->remove($invoice);
		$this->entityManager->flush();

		return new Response(null, Response::HTTP_NO_CONTENT);
	}

	private function applyInput(Invoice $invoice, InvoiceInput $input): ?string
	{
		if ($input->project_id === null || $input->invoice_date === null || $input->currency === null || $input->items === []) {
			return 'Project, invoice_date, currency and at least one line item are required.';
		}

		$project = $this->projectRepository->find($input->project_id);
		if (!$project instanceof Project || $project->getClient() === null) {
			return 'A valid project linked to a client is required.';
		}
		try {
			$invoiceDate = new \DateTimeImmutable($input->invoice_date);
			$dueDate = $input->due_date !== null ? new \DateTimeImmutable($input->due_date) : null;
		} catch (\Exception) {
			return 'Invoice dates must be valid calendar dates.';
		}
		if ($dueDate !== null && $dueDate < $invoiceDate) {
			return 'Due date must be on or after the invoice date.';
		}

		$items = [];
		$totalMinor = 0;
		$seenTaskIds = [];
		foreach ($input->items as $line) {
			if (!is_array($line) || !isset($line['description'], $line['amount']) || !is_string($line['description']) || !is_string($line['amount'])) {
				return 'Each line item requires a description and amount.';
			}
			$description = trim($line['description']);
			$minor = $this->money->parseMinor($line['amount']);
			if ($description === '' || iconv_strlen($description, 'UTF-8') > 255 || $minor === null) {
				return 'Line descriptions and amounts must be valid; amounts allow up to two decimal places.';
			}
			$taskId = $line['task_id'] ?? null;
			if ($taskId !== null) {
				if (!is_int($taskId) || $taskId < 1 || isset($seenTaskIds[$taskId])) {
					return 'A task can appear only once and must have a valid id.';
				}
				$task = $this->taskRepository->find($taskId);
				if (!$task instanceof Task || $task->getProject()?->getId() !== $project->getId()) {
					return 'Every task line must belong to the selected project.';
				}
				$seenTaskIds[$taskId] = true;
				$description = (string) $task->getTitle();
			}
			if ($minor > PHP_INT_MAX - $totalMinor) {
				return 'Invoice total is too large.';
			}
			$totalMinor += $minor;
			$items[] = ['task_id' => $taskId, 'description' => $description, 'amount' => $this->money->formatMinor($minor)];
		}
		if ($totalMinor < 1) {
			return 'Invoice total must be greater than zero.';
		}

		$invoice->setDetails(
			$project->getClient(),
			$project,
			$project->getManager(),
			(string) $project->getClient()->getTitle(),
			(string) $project->getTitle(),
			$project->getManager()?->getUserIdentifier(),
			$invoiceDate,
			$dueDate,
			$input->currency,
			$items,
			$totalMinor,
		);

		return null;
	}

	/** @return array<string, int|string|\DateTimeImmutable> */
	private function parseFilters(Request $request): array
	{
		$filters = [];
		foreach (['client_id', 'project_id', 'manager_id'] as $key) {
			$value = $request->query->get($key);
			if ($value !== null && $value !== '') {
				if (!ctype_digit((string) $value) || (int) $value < 1) {
					return ['error' => ucfirst(str_replace('_', ' ', $key)).' must be a positive integer.'];
				}
				$filters[$key] = (int) $value;
			}
		}
		foreach (['from', 'to'] as $key) {
			$value = $request->query->get($key);
			if ($value !== null && $value !== '') {
				$date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $value);
				if (!$date || $date->format('Y-m-d') !== $value) {
					return ['error' => 'Date filters must use YYYY-MM-DD.'];
				}
				$filters[$key] = $date;
			}
		}
		if (isset($filters['from'], $filters['to']) && $filters['from'] > $filters['to']) {
			return ['error' => 'The start date must be on or before the end date.'];
		}
		$currency = $request->query->get('currency');
		if ($currency !== null && $currency !== '') {
			if (!in_array($currency, ['EUR', 'GBP', 'USD'], true)) {
				return ['error' => 'Currency must be EUR, GBP or USD.'];
			}
			$filters['currency'] = $currency;
		}
		foreach (['min' => 'min_minor', 'max' => 'max_minor'] as $key => $target) {
			$value = $request->query->get($key);
			if ($value !== null && $value !== '') {
				$minor = $this->money->parseMinor((string) $value);
				if ($minor === null) {
					return ['error' => 'Amount filters must be non-negative amounts with at most two decimals.'];
				}
				$filters[$target] = $minor;
			}
		}
		if ((isset($filters['min_minor']) || isset($filters['max_minor'])) && !isset($filters['currency'])) {
			return ['error' => 'Choose a currency when filtering by amount.'];
		}
		if (isset($filters['min_minor'], $filters['max_minor']) && $filters['min_minor'] > $filters['max_minor']) {
			return ['error' => 'The minimum amount must not exceed the maximum amount.'];
		}

		return $filters;
	}

	/** @return array<string, mixed> */
	private function serializeInvoice(Invoice $invoice): array
	{
		$paidMinor = $this->paymentRepository->sumMinorForInvoice((int) $invoice->getId());
		return [
			'id' => $invoice->getId(),
			'invoice_number' => $invoice->getInvoiceNumber(),
			'client_id' => $invoice->getClient()?->getId(),
			'client_name' => $invoice->getClientName(),
			'project_id' => $invoice->getProject()?->getId(),
			'sales_order_id' => $invoice->getSalesOrder()?->getId(),
			'project_name' => $invoice->getProjectName(),
			'manager_id' => $invoice->getManager()?->getId(),
			'manager_name' => $invoice->getManagerName(),
			'invoice_date' => $invoice->getInvoiceDate()->format('Y-m-d'),
			'due_date' => $invoice->getDueDate()?->format('Y-m-d'),
			'currency' => $invoice->getCurrency(),
			'items' => $invoice->getItems(),
			'total' => $this->money->formatMinor($invoice->getTotalMinor()),
			'paid_total' => $this->money->formatMinor($paidMinor),
			'balance_due' => $this->money->formatMinor(max(0, $invoice->getTotalMinor() - $paidMinor)),
			'status' => $invoice->getStatus(),
			'created_at' => $invoice->getCreatedAt()->format(\DateTimeInterface::ATOM),
			'updated_at' => $invoice->getUpdatedAt()->format(\DateTimeInterface::ATOM),
		];
	}

	private function parseDate(string $value): \DateTimeImmutable
	{
		$date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
		if ($date === false || $date->format('Y-m-d') !== $value) {
			throw new \InvalidArgumentException('Invalid calendar date.');
		}

		return $date;
	}

}