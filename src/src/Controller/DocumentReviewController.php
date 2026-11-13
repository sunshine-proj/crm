<?php

namespace App\Controller;

use App\Dto\DocumentCommentInput;
use App\Entity\Document;
use App\Entity\DocumentComment;
use App\Entity\DocumentEvent;
use App\Entity\DocumentVersion;
use App\Entity\User;
use App\Repository\DocumentCommentRepository;
use App\Repository\DocumentRepository;
use App\Repository\DocumentVersionRepository;
use App\Service\Dms\DocumentAccess;
use App\Service\Dms\VersionComparison;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Remarks on versions and version comparison. Anyone who can read a document can comment on it; a comment is
 * resolved by its writer, the document's author or a registry clerk.
 */
#[Route('/api/dms/documents/{id<\d+>}', name: 'api_dms_review_')]
#[IsGranted('ROLE_USER')]
final class DocumentReviewController extends AbstractController
{
    public function __construct(
        private readonly DocumentRepository $documentRepository,
        private readonly DocumentVersionRepository $versionRepository,
        private readonly DocumentCommentRepository $commentRepository,
        private readonly DocumentAccess $access,
        private readonly VersionComparison $comparison,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/comments', name: 'comments_list', methods: ['GET'])]
    public function comments(int $id): JsonResponse
    {
        $document = $this->readable($id);
        if ($document === null) {
            return $this->notFound('Document not found.');
        }

        return $this->json(array_map(fn (DocumentComment $comment): array => $this->serialize($comment, $document), $this->commentRepository->findForDocument($document)));
    }

    #[Route('/comments', name: 'comments_create', methods: ['POST'])]
    public function comment(int $id, #[MapRequestPayload] DocumentCommentInput $input): JsonResponse
    {
        $document = $this->readable($id);
        if ($document === null) {
            return $this->notFound('Document not found.');
        }
        if (!$this->versionRepository->findOneBy(['document' => $document, 'number' => $input->version_number]) instanceof DocumentVersion) {
            return $this->notFound('Version not found.');
        }
        try {
            $comment = new DocumentComment($document, (int) $input->version_number, $this->viewer(), $input->quote, (string) $input->body);
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['error' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        }
        $this->entityManager->persist($comment);
        $this->entityManager->persist(new DocumentEvent($document, $this->viewer()->getUserIdentifier(), 'commented', ['version' => $input->version_number]));
        $this->entityManager->flush();

        return $this->json($this->serialize($comment, $document), Response::HTTP_CREATED);
    }

    #[Route('/comments/{commentId<\d+>}/resolve', name: 'comments_resolve', methods: ['POST'])]
    public function resolve(int $id, int $commentId): JsonResponse
    {
        $document = $this->readable($id);
        $comment = $this->commentRepository->find($commentId);
        if ($document === null || !$comment instanceof DocumentComment || $comment->getDocument() !== $document) {
            return $this->notFound('Comment not found.');
        }
        if (!$this->canResolve($comment, $document)) {
            return $this->json(['error' => 'Only the writer, the document author or a registry clerk can resolve this comment.'], Response::HTTP_FORBIDDEN);
        }
        try {
            $comment->resolve($this->viewer()->getUserIdentifier());
        } catch (\DomainException $exception) {
            return $this->json(['error' => $exception->getMessage()], Response::HTTP_CONFLICT);
        }
        $this->entityManager->flush();

        return $this->json($this->serialize($comment, $document));
    }

    /** Text differences between two versions: `?from=1&to=2`. */
    #[Route('/versions/compare', name: 'compare', methods: ['GET'])]
    public function compare(int $id, Request $request): JsonResponse
    {
        $document = $this->readable($id);
        if ($document === null) {
            return $this->notFound('Document not found.');
        }
        $versions = [];
        foreach (['from', 'to'] as $key) {
            $number = $request->query->get($key);
            if ($number === null || !ctype_digit((string) $number)) {
                return $this->json(['error' => 'from and to must be version numbers.'], Response::HTTP_BAD_REQUEST);
            }
            $versions[$key] = $this->versionRepository->findOneBy(['document' => $document, 'number' => (int) $number]);
            if (!$versions[$key] instanceof DocumentVersion) {
                return $this->notFound(sprintf('Version %d not found.', (int) $number));
            }
        }

        return $this->json(['from' => $versions['from']->getNumber(), 'to' => $versions['to']->getNumber()]
            + $this->comparison->compare($versions['from']->getExtractedText(), $versions['to']->getExtractedText()));
    }

    /** @return array<string, mixed> */
    private function serialize(DocumentComment $comment, Document $document): array
    {
        return [
            'id' => $comment->getId(),
            'version_number' => $comment->getVersionNumber(),
            'author' => $comment->getAuthorEmail(),
            'quote' => $comment->getQuote(),
            'body' => $comment->getBody(),
            'created_at' => $comment->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'resolved_at' => $comment->getResolvedAt()?->format(\DateTimeInterface::ATOM),
            'resolved_by' => $comment->getResolvedBy(),
            'can_resolve' => $comment->getResolvedAt() === null && $this->canResolve($comment, $document),
        ];
    }

    private function canResolve(DocumentComment $comment, Document $document): bool
    {
        return $comment->getAuthor()?->getId() === $this->viewer()->getId() || $this->access->canManage($this->viewer(), $document);
    }

    private function readable(int $id): ?Document
    {
        $document = $this->documentRepository->find($id);

        return $document instanceof Document && $this->access->canRead($this->viewer(), $document) ? $document : null;
    }

    private function notFound(string $message): JsonResponse
    {
        return $this->json(['error' => $message], Response::HTTP_NOT_FOUND);
    }

    private function viewer(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
