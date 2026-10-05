<?php

namespace App\Controller;

use App\Dto\NoteInput;
use App\Entity\Note;
use App\Entity\User;
use App\Repository\NoteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/notes', name: 'api_notes_')]
final class NoteController extends AbstractController
{
    public function __construct(
        private readonly NoteRepository $noteRepository,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire(service: 'monolog.logger.audit')]
        private readonly LoggerInterface $auditLogger,
    ) {
    }

    #[Route('/{entityType<client|project|task>}/{entityId<\d+>}', name: 'list', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function list(string $entityType, int $entityId): JsonResponse
    {
        $notes = $this->noteRepository->findByEntity($entityType, $entityId);

        return $this->json(array_map(fn (Note $note): array => $this->serializeItem($note), $notes));
    }

    #[Route('/{entityType<client|project|task>}/{entityId<\d+>}', name: 'create', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function create(string $entityType, int $entityId, #[MapRequestPayload] NoteInput $input): JsonResponse
    {
        if ($input->content === null || trim($input->content) === '') {
            return $this->json(['error' => 'Note content is required.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $now = new \DateTimeImmutable();
        $author = $this->getUser();
        $note = (new Note())
            ->setEntityType($entityType)
            ->setEntityId($entityId)
            ->setContent(trim($input->content))
            ->setAuthor($author instanceof User ? $author : null)
            ->setCreatedAt($now)
            ->setUpdatedAt($now);

        $this->entityManager->persist($note);
        $this->entityManager->flush();

        $this->auditLogger->info('CRM note created.', [
            'action' => 'crm.note.create',
            'actor' => $this->getAuditActor(),
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'note_id' => $note->getId(),
        ]);

        return $this->json($this->serializeItem($note), JsonResponse::HTTP_CREATED);
    }

    #[Route('/{entityType<client|project|task>}/{entityId<\d+>}/{noteId<\d+>}', name: 'update', methods: ['PUT', 'PATCH'])]
    #[IsGranted('ROLE_USER')]
    public function update(string $entityType, int $entityId, int $noteId, #[MapRequestPayload] NoteInput $input): JsonResponse
    {
        $note = $this->noteRepository->find($noteId);
        if ($note === null || $note->getEntityType() !== $entityType || $note->getEntityId() !== $entityId) {
            return $this->json(['error' => 'Note not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        if ($input->content === null || trim($input->content) === '') {
            return $this->json(['error' => 'Note content is required.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $note->setContent(trim($input->content))->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        $this->auditLogger->info('CRM note updated.', [
            'action' => 'crm.note.update',
            'actor' => $this->getAuditActor(),
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'note_id' => $noteId,
        ]);

        return $this->json($this->serializeItem($note));
    }

    #[Route('/{entityType<client|project|task>}/{entityId<\d+>}/{noteId<\d+>}', name: 'delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_USER')]
    public function delete(string $entityType, int $entityId, int $noteId): JsonResponse
    {
        $note = $this->noteRepository->find($noteId);
        if ($note === null || $note->getEntityType() !== $entityType || $note->getEntityId() !== $entityId) {
            return $this->json(['error' => 'Note not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        $this->entityManager->remove($note);
        $this->entityManager->flush();

        $this->auditLogger->info('CRM note deleted.', [
            'action' => 'crm.note.delete',
            'actor' => $this->getAuditActor(),
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'note_id' => $noteId,
        ]);

        return new JsonResponse(null, JsonResponse::HTTP_NO_CONTENT);
    }

    private function getAuditActor(): string
    {
        $user = $this->getUser();

        return $user instanceof User ? $user->getUserIdentifier() : 'anonymous';
    }

    /** @return array<string, int|string|null> */
    private function serializeItem(Note $note): array
    {
        return [
            'id' => $note->getId(),
            'entity_type' => $note->getEntityType(),
            'entity_id' => $note->getEntityId(),
            'content' => $note->getContent(),
            'author_email' => $note->getAuthor()?->getUserIdentifier(),
            'created_at' => $note->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'updated_at' => $note->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
