<?php

namespace App\Controller;

use App\Dto\DocumentInput;
use App\Dto\DocumentLinkInput;
use App\Dto\DocumentReadersInput;
use App\Dto\DocumentRecordsInput;
use App\Dto\DocumentSearch;
use App\Entity\Document;
use App\Entity\DocumentAttachment;
use App\Entity\DocumentLink;
use App\Entity\DocumentVersion;
use App\Entity\User;
use App\Repository\DocumentAttachmentRepository;
use App\Repository\DocumentDisposalRepository;
use App\Repository\DocumentEventRepository;
use App\Repository\DocumentLinkRepository;
use App\Repository\DocumentRepository;
use App\Repository\DocumentVersionRepository;
use App\Service\Dms\AcknowledgementService;
use App\Service\Dms\DmsFileStorage;
use App\Service\Dms\DocumentAccess;
use App\Service\Dms\DocumentAccessDenied;
use App\Service\Dms\DocumentAudit;
use App\Service\Dms\DocumentCardPdf;
use App\Service\Dms\DocumentService;
use App\Service\Dms\DocumentViews;
use App\Service\Dms\NotFound;
use App\Service\Dms\ScannerUnavailable;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Document registry. Every route needs a signed-in user; record-level access (author, readers, unit heads, registry
 * clerks, confidentiality) is decided by DocumentAccess. Documents the caller may not read answer 404, so their
 * existence is not disclosed.
 */
#[Route('/api/dms/documents', name: 'api_dms_documents_')]
#[IsGranted('ROLE_USER')]
final class DocumentController extends AbstractController
{
    public function __construct(
        private readonly DocumentRepository $documentRepository,
        private readonly DocumentVersionRepository $versionRepository,
        private readonly DocumentAttachmentRepository $attachmentRepository,
        private readonly DocumentLinkRepository $linkRepository,
        private readonly DocumentEventRepository $eventRepository,
        private readonly DocumentService $documents,
        private readonly DocumentAccess $access,
        private readonly DocumentViews $views,
        private readonly DmsFileStorage $storage,
        private readonly DocumentCardPdf $cardPdf,
        private readonly AcknowledgementService $acknowledgements,
        private readonly DocumentAudit $audit,
        private readonly DocumentDisposalRepository $disposalRepository,
        #[Autowire(service: 'monolog.logger.audit')] private readonly LoggerInterface $auditLogger,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $search = $this->search($request);
        if (is_string($search)) {
            return $this->error($search, Response::HTTP_BAD_REQUEST);
        }
        $viewer = $this->viewer();
        $result = $this->documentRepository->search($search, $this->access->isRegistrar() ? null : $viewer, $this->access->managedUnitIds($viewer), $this->access->actingForIds($viewer));
        $current = $this->versionRepository->findLatestFor($result['items']);

        return $this->json([
            'items' => array_map(fn (Document $document): array => $this->views->summary($document, $current[(int) $document->getId()] ?? null, $viewer), $result['items']),
            'total' => $result['total'],
            'limit' => $search->limit,
            'offset' => $search->offset,
        ]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(#[MapRequestPayload] DocumentInput $input): JsonResponse
    {
        return $this->guard(function () use ($input): JsonResponse {
            $document = $this->documents->create($input, $this->viewer());
            $this->auditLogger->info('Document created.', ['document_id' => $document->getId(), 'actor' => $this->viewer()->getUserIdentifier()]);

            return $this->json($this->detail($document), Response::HTTP_CREATED);
        });
    }

    #[Route('/{id<\d+>}', name: 'show', methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        return $this->guard(function () use ($id): JsonResponse {
            $document = $this->readable($id);
            $this->flagConfidentialRead($document, 'details');
            $this->documents->recordView($document, $this->viewer());

            return $this->json($this->detail($document));
        });
    }

    #[Route('/{id<\d+>}', name: 'update', methods: ['PATCH', 'PUT'])]
    public function update(int $id, #[MapRequestPayload] DocumentInput $input): JsonResponse
    {
        return $this->guard(function () use ($id, $input): JsonResponse {
            $document = $this->readable($id);
            $this->documents->update($document, $input, $this->viewer());

            return $this->json($this->detail($document));
        });
    }

    #[Route('/{id<\d+>}', name: 'delete', methods: ['DELETE'])]
    public function delete(int $id): Response
    {
        return $this->guard(function () use ($id): Response {
            $this->documents->delete($this->readable($id), $this->viewer());
            $this->auditLogger->info('Document deleted.', ['document_id' => $id, 'actor' => $this->viewer()->getUserIdentifier()]);

            return new Response(null, Response::HTTP_NO_CONTENT);
        });
    }

    /** Multipart upload of a new version: `file`, optional `comment`, optional `check_in=1` to release the caller's lock. */
    #[Route('/{id<\d+>}/versions', name: 'versions_upload', methods: ['POST'])]
    public function uploadVersion(int $id, Request $request): JsonResponse
    {
        return $this->guard(function () use ($id, $request): JsonResponse {
            $document = $this->readable($id);
            $file = $request->files->get('file');
            if (!$file instanceof UploadedFile) {
                return $this->error('Upload a file using the file field.', Response::HTTP_BAD_REQUEST);
            }
            $comment = $request->request->get('comment');
            $this->documents->uploadVersion($document, $file, is_string($comment) ? $comment : null, $request->request->getBoolean('check_in'), $this->viewer());

            return $this->json($this->detail($document), Response::HTTP_CREATED);
        });
    }

    /** The file of one version; `inline=1` asks for in-browser preview, honoured only for safe types. */
    #[Route('/{id<\d+>}/versions/{number<\d+>}/file', name: 'versions_file', methods: ['GET'])]
    public function versionFile(int $id, int $number, Request $request): Response
    {
        return $this->guard(function () use ($id, $number, $request): Response {
            $document = $this->readable($id);
            $version = $this->versionRepository->findOneBy(['document' => $document, 'number' => $number]);
            if (!$version instanceof DocumentVersion) {
                throw new NotFound('Version not found.');
            }
            if ($version->getFileDisposedAt() !== null) {
                return $this->error('This file was destroyed when the document was disposed of; its SHA-256 remains on record.', Response::HTTP_GONE);
            }
            $this->flagConfidentialRead($document, 'version '.$number);
            $this->documents->recordDownload($document, $this->viewer(), 'v'.$number.' '.$version->getOriginalName(), $version->getSha256());
            // Opening the requested version is what an acknowledgement request counts as "viewed".
            $this->acknowledgements->markViewed($document, $this->viewer(), $number);

            return $this->serveFile($version->getStoredName(), $version->getOriginalName(), $version->getMimeType(), $request->query->getBoolean('inline'));
        });
    }

    #[Route('/{id<\d+>}/checkout', name: 'checkout', methods: ['POST'])]
    public function checkOut(int $id, Request $request): JsonResponse
    {
        return $this->guard(function () use ($id, $request): JsonResponse {
            $document = $this->readable($id);
            $body = json_decode($request->getContent() ?: '{}', true);
            $this->documents->checkOut($document, $this->viewer(), is_array($body) && ($body['force'] ?? false) === true);

            return $this->json($this->detail($document));
        });
    }

    #[Route('/{id<\d+>}/checkin', name: 'checkin', methods: ['POST'])]
    public function checkIn(int $id): JsonResponse
    {
        return $this->guard(function () use ($id): JsonResponse {
            $document = $this->readable($id);
            $this->documents->checkIn($document, $this->viewer());

            return $this->json($this->detail($document));
        });
    }

    #[Route('/{id<\d+>}/register', name: 'register', methods: ['POST'])]
    #[IsGranted('ROLE_DMS_ADMIN')]
    public function register(int $id): JsonResponse
    {
        return $this->guard(function () use ($id): JsonResponse {
            $document = $this->readable($id);
            $this->documents->register($document, $this->viewer());
            $this->auditLogger->info('Document registered.', ['document_id' => $id, 'number' => $document->getRegistrationNumber(), 'actor' => $this->viewer()->getUserIdentifier()]);

            return $this->json($this->detail($document));
        });
    }

    #[Route('/{id<\d+>}/archive', name: 'archive', methods: ['POST'])]
    #[IsGranted('ROLE_DMS_ADMIN')]
    public function archive(int $id): JsonResponse
    {
        return $this->guard(function () use ($id): JsonResponse {
            $document = $this->readable($id);
            $this->documents->archive($document, $this->viewer());
            $this->auditLogger->info('Document archived.', ['document_id' => $id, 'actor' => $this->viewer()->getUserIdentifier()]);

            return $this->json($this->detail($document));
        });
    }

    #[Route('/{id<\d+>}/readers', name: 'readers', methods: ['PUT'])]
    public function readers(int $id, #[MapRequestPayload] DocumentReadersInput $input): JsonResponse
    {
        return $this->guard(function () use ($id, $input): JsonResponse {
            $document = $this->readable($id);
            $this->documents->replaceReaders($document, $input->user_ids, $this->viewer());
            $this->auditLogger->info('Document readers changed.', ['document_id' => $id, 'actor' => $this->viewer()->getUserIdentifier()]);

            return $this->json($this->detail($document));
        });
    }

    #[Route('/{id<\d+>}/attachments', name: 'attachments_upload', methods: ['POST'])]
    public function uploadAttachment(int $id, Request $request): JsonResponse
    {
        return $this->guard(function () use ($id, $request): JsonResponse {
            $document = $this->readable($id);
            $file = $request->files->get('file');
            if (!$file instanceof UploadedFile) {
                return $this->error('Upload a file using the file field.', Response::HTTP_BAD_REQUEST);
            }
            $this->documents->addAttachment($document, $file, $this->viewer());

            return $this->json($this->detail($document), Response::HTTP_CREATED);
        });
    }

    #[Route('/{id<\d+>}/attachments/{attachmentId<\d+>}/file', name: 'attachments_file', methods: ['GET'])]
    public function attachmentFile(int $id, int $attachmentId, Request $request): Response
    {
        return $this->guard(function () use ($id, $attachmentId, $request): Response {
            $document = $this->readable($id);
            $attachment = $this->attachment($document, $attachmentId);
            if ($attachment->getFileDisposedAt() !== null) {
                return $this->error('This file was destroyed when the document was disposed of; its SHA-256 remains on record.', Response::HTTP_GONE);
            }
            $this->flagConfidentialRead($document, 'attachment');
            $this->documents->recordDownload($document, $this->viewer(), 'attachment '.$attachment->getOriginalName(), $attachment->getSha256());

            return $this->serveFile($attachment->getStoredName(), $attachment->getOriginalName(), $attachment->getMimeType(), $request->query->getBoolean('inline'));
        });
    }

    #[Route('/{id<\d+>}/attachments/{attachmentId<\d+>}', name: 'attachments_delete', methods: ['DELETE'])]
    public function deleteAttachment(int $id, int $attachmentId): Response
    {
        return $this->guard(function () use ($id, $attachmentId): Response {
            $this->documents->removeAttachment($this->attachment($this->readable($id), $attachmentId), $this->viewer());

            return new Response(null, Response::HTTP_NO_CONTENT);
        });
    }

    #[Route('/{id<\d+>}/links', name: 'links_create', methods: ['POST'])]
    public function createLink(int $id, #[MapRequestPayload] DocumentLinkInput $input): JsonResponse
    {
        return $this->guard(function () use ($id, $input): JsonResponse {
            $document = $this->readable($id);
            $this->documents->addLink($document, (int) $input->target_id, (string) $input->kind, $this->viewer());

            return $this->json($this->detail($document), Response::HTTP_CREATED);
        });
    }

    #[Route('/{id<\d+>}/links/{linkId<\d+>}', name: 'links_delete', methods: ['DELETE'])]
    public function deleteLink(int $id, int $linkId): Response
    {
        return $this->guard(function () use ($id, $linkId): Response {
            $document = $this->readable($id);
            $link = $this->linkRepository->find($linkId);
            if (!$link instanceof DocumentLink || $link->getSource() !== $document) {
                throw new NotFound('Link not found.');
            }
            $this->documents->removeLink($link, $this->viewer());

            return new Response(null, Response::HTTP_NO_CONTENT);
        });
    }

    /** Registration card PDF: identity, versions with hashes, and history. */
    #[Route('/{id<\d+>}/card.pdf', name: 'card_pdf', methods: ['GET'])]
    public function cardPdf(int $id): Response
    {
        return $this->guard(function () use ($id): Response {
            $document = $this->readable($id);
            $this->flagConfidentialRead($document, 'card');
            $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $document->getRegistrationNumber() ?? 'document-'.$id).'-card.pdf';
            $response = new Response($this->cardPdf->render($document, $this->versionRepository->findForDocument($document), $this->eventRepository->findForDocument($document)));
            $response->headers->set('Content-Type', 'application/pdf');
            $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $name));

            return $response;
        });
    }

    /** Clerks put a document under legal hold: it cannot be deleted or disposed of until released. */
    #[Route('/{id<\d+>}/legal-hold', name: 'legal_hold_place', methods: ['POST'])]
    #[IsGranted('ROLE_DMS_ADMIN')]
    public function placeLegalHold(int $id, #[MapRequestPayload] DocumentRecordsInput $input): JsonResponse
    {
        return $this->guard(function () use ($id, $input): JsonResponse {
            $document = $this->readable($id);
            $this->documents->placeLegalHold($document, $this->viewer(), $input->reason);
            $this->auditLogger->notice('Legal hold placed.', ['document_id' => $id, 'actor' => $this->viewer()->getUserIdentifier()]);

            return $this->json($this->detail($document));
        });
    }

    #[Route('/{id<\d+>}/legal-hold', name: 'legal_hold_release', methods: ['DELETE'])]
    #[IsGranted('ROLE_DMS_ADMIN')]
    public function releaseLegalHold(int $id, Request $request): JsonResponse
    {
        return $this->guard(function () use ($id, $request): JsonResponse {
            $document = $this->readable($id);
            $body = json_decode($request->getContent() ?: '{}', true);
            $this->documents->releaseLegalHold($document, $this->viewer(), is_array($body) && is_string($body['comment'] ?? null) ? $body['comment'] : null);
            $this->auditLogger->notice('Legal hold released.', ['document_id' => $id, 'actor' => $this->viewer()->getUserIdentifier()]);

            return $this->json($this->detail($document));
        });
    }

    /** The author or a clerk records the periodic review of a registered document and sets the next date. */
    #[Route('/{id<\d+>}/review', name: 'review', methods: ['POST'])]
    public function recordReview(int $id, #[MapRequestPayload] DocumentRecordsInput $input): JsonResponse
    {
        return $this->guard(function () use ($id, $input): JsonResponse {
            $document = $this->readable($id);
            $next = $input->next_review_on !== null && $input->next_review_on !== '' ? \DateTimeImmutable::createFromFormat('!Y-m-d', $input->next_review_on) : null;
            $this->documents->recordReview($document, $this->viewer(), $next ?: null, $input->comment);

            return $this->json($this->detail($document));
        });
    }

    /**
     * The document's audit trail (its events and every approval action), for the author and clerks.
     * Filters: `action` (prefix), `actor`, `from`, `to` (YYYY-MM-DD); `format=csv` downloads it.
     */
    #[Route('/{id<\d+>}/audit', name: 'audit', methods: ['GET'])]
    public function auditTrail(int $id, Request $request): Response
    {
        return $this->guard(function () use ($id, $request): Response {
            $document = $this->readable($id);
            if (!$this->access->canManage($this->viewer(), $document)) {
                throw new DocumentAccessDenied('Only the author and registry clerks can see the audit trail.');
            }
            $dates = [];
            foreach (['from', 'to'] as $key) {
                $value = (string) $request->query->get($key, '');
                $date = $value === '' ? null : \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
                if ($date === false || ($date !== null && $date->format('Y-m-d') !== $value)) {
                    throw new \InvalidArgumentException(sprintf('%s must be a date in YYYY-MM-DD form.', $key));
                }
                $dates[$key] = $date;
            }
            $trail = $this->audit->entries($document, $request->query->get('action'), $request->query->get('actor'), $dates['from'], $dates['to']);
            $this->auditLogger->info('Document audit trail read.', ['document_id' => $id, 'format' => $request->query->get('format', 'json'), 'actor' => $this->viewer()->getUserIdentifier()]);
            if ($request->query->get('format') !== 'csv') {
                return $this->json($trail);
            }
            $handle = fopen('php://temp', 'w+');
            fputcsv($handle, ['When', 'Source', 'Action', 'Actor', 'On behalf of', 'Details'], escape: '');
            foreach ($trail['entries'] as $entry) {
                $details = implode('; ', array_map(static fn (string $key, mixed $value): string => $key.'='.(is_scalar($value) ? (string) $value : json_encode($value)), array_keys($entry['details']), $entry['details']));
                fputcsv($handle, array_map(self::csvCell(...), [$entry['at'], $entry['source'], $entry['action'], $entry['actor'], (string) $entry['on_behalf_of'], $details]), escape: '');
            }
            rewind($handle);
            $csv = "\xEF\xBB\xBF".stream_get_contents($handle);
            fclose($handle);

            return new Response($csv, Response::HTTP_OK, [
                'Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, sprintf('document-%d-audit.csv', $id)),
                'X-Content-Type-Options' => 'nosniff',
            ]);
        });
    }

    /** Spreadsheet formula injection guard. */
    private static function csvCell(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }

    /** @return array<string, mixed> */
    private function detail(Document $document): array
    {
        return $this->views->detail(
            $document,
            $this->versionRepository->findForDocument($document),
            $this->attachmentRepository->findForDocument($document),
            $this->linkRepository->findInvolving($document),
            $this->eventRepository->findForDocument($document),
            $this->viewer(),
        ) + ['disposals' => array_map(static fn ($disposal): array => [
            'id' => $disposal->getId(), 'status' => $disposal->getStatus(), 'proposed_by' => $disposal->getProposedBy(),
            'proposed_at' => $disposal->getProposedAt()->format(\DateTimeInterface::ATOM), 'reason' => $disposal->getReason(),
            'decided_by' => $disposal->getDecidedBy(), 'decided_at' => $disposal->getDecidedAt()?->format(\DateTimeInterface::ATOM), 'decision_comment' => $disposal->getDecisionComment(),
        ], $this->access->isRegistrar() ? $this->disposalRepository->findForDocument($document) : [])];
    }

    private function readable(int $id): Document
    {
        $document = $this->documentRepository->find($id);
        if (!$document instanceof Document || !$this->access->canRead($this->viewer(), $document)) {
            throw new NotFound('Document not found.');
        }

        return $document;
    }

    private function attachment(Document $document, int $attachmentId): DocumentAttachment
    {
        $attachment = $this->attachmentRepository->find($attachmentId);
        if (!$attachment instanceof DocumentAttachment || $attachment->getDocument() !== $document) {
            throw new NotFound('Attachment not found.');
        }

        return $attachment;
    }

    private function serveFile(string $storedName, string $originalName, string $mimeType, bool $inline): Response
    {
        $path = $this->storage->path($storedName);
        if (!is_file($path)) {
            return $this->error('The file is missing from storage.', Response::HTTP_NOT_FOUND);
        }
        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', $mimeType);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
        $disposition = $inline && DmsFileStorage::isPreviewable($mimeType) ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT;
        $fallback = preg_replace('/[^\x20-\x7E]|[%\/\\\\"]/', '_', $originalName) ?: 'document';
        $response->setContentDisposition($disposition, $originalName, $fallback);

        return $response;
    }

    private function flagConfidentialRead(Document $document, string $what): void
    {
        if ($document->isConfidential()) {
            $this->auditLogger->info('Confidential document read.', ['document_id' => $document->getId(), 'part' => $what, 'actor' => $this->viewer()->getUserIdentifier()]);
        }
    }

    private function search(Request $request): DocumentSearch|string
    {
        $query = $request->query;
        $ints = [];
        foreach (['type_id', 'owner_unit_id', 'author_id', 'client_id', 'project_id'] as $key) {
            $value = $query->get($key);
            if ($value !== null && $value !== '' && (!ctype_digit((string) $value) || (int) $value < 1)) {
                return sprintf('%s must be a positive integer.', $key);
            }
            $ints[$key] = $value === null || $value === '' ? null : (int) $value;
        }
        $status = $query->get('status');
        if ($status !== null && $status !== '' && !in_array($status, Document::STATUSES, true)) {
            return 'Unsupported document status.';
        }
        $dates = [];
        foreach (['from', 'to'] as $key) {
            $value = (string) $query->get($key, '');
            $date = $value === '' ? null : \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            if ($date === false || ($date !== null && $date->format('Y-m-d') !== $value)) {
                return sprintf('%s must be a date in YYYY-MM-DD form.', $key);
            }
            $dates[$key] = $date;
        }
        $limit = $query->getInt('limit', 50);
        $offset = $query->getInt('offset', 0);
        if ($limit < 1 || $limit > 200 || $offset < 0) {
            return 'limit must be 1-200 and offset non-negative.';
        }
        $tag = $query->get('tag');
        if ($query->getBoolean('mine')) {
            $user = $this->getUser();
            $ints['author_id'] = $user instanceof User ? $user->getId() : null;
        }

        return new DocumentSearch(
            text: $query->get('q') !== null ? mb_substr((string) $query->get('q'), 0, 200) : null,
            typeId: $ints['type_id'],
            status: $status !== '' ? $status : null,
            ownerUnitId: $ints['owner_unit_id'],
            authorId: $ints['author_id'],
            tag: $tag !== null && $tag !== '' ? (string) $tag : null,
            clientId: $ints['client_id'],
            projectId: $ints['project_id'],
            from: $dates['from'],
            to: $dates['to'],
            limit: $limit,
            offset: $offset,
        );
    }

    /** @param callable(): Response $action */
    private function guard(callable $action): Response
    {
        try {
            return $action();
        } catch (NotFound $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (DocumentAccessDenied $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_FORBIDDEN);
        } catch (\InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_BAD_REQUEST);
        } catch (\DomainException $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_CONFLICT);
        } catch (ScannerUnavailable $exception) {
            $this->auditLogger->error('Upload refused: virus scanner unavailable.', ['error' => $exception->getMessage()]);

            return $this->error('Uploads are paused because the virus scanner is not available. Try again later.', Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    private function viewer(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function error(string $message, int $status): JsonResponse
    {
        return $this->json(['error' => $message], $status);
    }
}
