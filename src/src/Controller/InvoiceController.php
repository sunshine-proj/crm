<?php

namespace App\Controller;

use App\Dto\InvoiceInput;
use App\Entity\Invoice;
use App\Entity\Project;
use App\Entity\Task;
use App\Entity\User;
use App\Repository\InvoiceRepository;
use App\Repository\PaymentRepository;
use App\Repository\ProjectRepository;
use App\Repository\TaskRepository;
use App\Repository\UserRepository;
use App\Service\InvoiceMoney;
use App\Service\InvoicePdfService;
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
		private readonly PaymentRepository $paymentRepository,
		private readonly ProjectRepository $projectRepository,
		private readonly TaskRepository $taskRepository,
		private readonly UserRepository $userRepository,
		private readonly EntityManagerInterface $entityManager,
		private readonly InvoiceMoney $money,
		private readonly InvoicePdfService $pdfService,
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

		$invoice->setStatus('issued');
		$this->entityManager->flush();

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

}