<?php

namespace App\Controller;

use App\Dto\CreditNoteInput;
use App\Entity\CreditNote;
use App\Entity\Invoice;
use App\Entity\User;
use App\Repository\CreditNoteRepository;
use App\Repository\InvoiceRepository;
use App\Service\CreditNoteService;
use App\Service\InvoiceMoney;
use App\Service\InvoicePdfService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Corrections of issued invoices. Issued invoices are never edited; a credit note reverses revenue, tax and receivable. */
#[Route('/api', name: 'api_credit_notes_')]
#[IsGranted('ROLE_FINANCE')]
final class CreditNoteController extends AbstractController
{
    public function __construct(
        private readonly InvoiceRepository $invoiceRepository,
        private readonly CreditNoteRepository $creditNoteRepository,
        private readonly CreditNoteService $service,
        private readonly InvoicePdfService $pdfService,
        private readonly InvoiceMoney $money,
        #[Autowire(service: 'monolog.logger.audit')] private readonly LoggerInterface $auditLogger,
    ) {
    }

    #[Route('/invoices/{id<\d+>}/credit-notes', name: 'list', methods: ['GET'])]
    public function list(int $id): JsonResponse
    {
        if ($this->invoiceRepository->find($id) === null) {
            return $this->json(['error' => 'Invoice not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        return $this->json(array_map($this->serialize(...), $this->creditNoteRepository->forInvoice($id)));
    }

    #[Route('/invoices/{id<\d+>}/credit-notes', name: 'create', methods: ['POST'])]
    public function create(int $id, #[MapRequestPayload] CreditNoteInput $input): JsonResponse
    {
        $invoice = $this->invoiceRepository->find($id);
        if (!$invoice instanceof Invoice) {
            return $this->json(['error' => 'Invoice not found.'], JsonResponse::HTTP_NOT_FOUND);
        }
        $date = $input->credit_date !== null ? \DateTimeImmutable::createFromFormat('!Y-m-d', $input->credit_date) : new \DateTimeImmutable('today');
        if ($date === false) {
            return $this->json(['error' => 'The credit note date must use YYYY-MM-DD.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        $user = $this->getUser();
        $actor = $user instanceof User ? $user->getUserIdentifier() : 'anonymous';
        try {
            $note = $this->service->issue($invoice, (string) $input->reason, $input->items, $date, $actor);
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['error' => $exception->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
        } catch (\DomainException|UniqueConstraintViolationException $exception) {
            return $this->json(['error' => $exception instanceof \DomainException ? $exception->getMessage() : 'Another credit note was issued at the same time; please retry.'], JsonResponse::HTTP_CONFLICT);
        }
        $this->auditLogger->warning('Credit note issued.', ['credit_note_id' => $note->getId(), 'invoice_id' => $id, 'total_minor' => $note->getTotalMinor(), 'actor' => $actor]);

        return $this->json($this->serialize($note), JsonResponse::HTTP_CREATED);
    }

    #[Route('/credit-notes/{id<\d+>}/pdf', name: 'pdf', methods: ['GET'])]
    public function pdf(int $id): Response
    {
        $note = $this->creditNoteRepository->find($id);
        if (!$note instanceof CreditNote) {
            return $this->json(['error' => 'Credit note not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        return new Response($this->pdfService->renderCreditNote($note), Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.strtolower((string) $note->getCreditNoteNumber()).'.pdf"',
        ]);
    }

    /** @return array<string, mixed> */
    private function serialize(CreditNote $note): array
    {
        return [
            'id' => $note->getId(), 'credit_note_number' => $note->getCreditNoteNumber(), 'invoice_id' => $note->getInvoice()->getId(),
            'invoice_number' => $note->getInvoice()->getInvoiceNumber(), 'issue_date' => $note->getIssueDate()->format('Y-m-d'),
            'reason' => $note->getReason(), 'currency' => $note->getCurrency(), 'items' => $note->getItems(),
            'net_total' => $this->money->formatMinor($note->getNetMinor()), 'tax_total' => $this->money->formatMinor($note->getTaxMinor()),
            'total' => $this->money->formatMinor($note->getTotalMinor()),
            'tax_breakdown' => array_map(fn (array $row): array => ['tax_code' => $row['code'], 'rate_percent' => $this->money->formatMinor($row['rate_bp']), 'net' => $this->money->formatMinor($row['net_minor']), 'tax' => $this->money->formatMinor($row['tax_minor'])], $note->getTaxBreakdown()),
            'issued_by' => $note->getIssuedBy(), 'created_at' => $note->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
