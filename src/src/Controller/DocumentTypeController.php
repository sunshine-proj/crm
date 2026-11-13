<?php

namespace App\Controller;

use App\Dto\DocumentTypeInput;
use App\Entity\DocumentType;
use App\Entity\User;
use App\Repository\DocumentTypeRepository;
use App\Service\Dms\DocumentViews;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Document types (registry configuration): readable by everyone, maintained by registry clerks. Types are deactivated, not deleted. */
#[Route('/api/dms/types', name: 'api_dms_types_')]
#[IsGranted('ROLE_USER')]
final class DocumentTypeController extends AbstractController
{
    public function __construct(
        private readonly DocumentTypeRepository $typeRepository,
        private readonly DocumentViews $views,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire(service: 'monolog.logger.audit')] private readonly LoggerInterface $auditLogger,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        return $this->json(array_map($this->views->type(...), $this->typeRepository->findAllOrdered($request->query->getBoolean('active_only'))));
    }

    #[Route('', name: 'create', methods: ['POST'])]
    #[IsGranted('ROLE_DMS_ADMIN')]
    public function create(#[MapRequestPayload] DocumentTypeInput $input): JsonResponse
    {
        if ($input->code === null || $input->name === null) {
            return $this->json(['error' => 'Code and name are required.'], Response::HTTP_BAD_REQUEST);
        }
        if ($this->typeRepository->findOneBy(['code' => strtoupper(trim($input->code))]) !== null) {
            return $this->json(['error' => 'Another document type already uses this code.'], Response::HTTP_CONFLICT);
        }
        try {
            $type = new DocumentType($input->code, $input->name);
            $this->apply($type, $input);
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['error' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        }
        $this->entityManager->persist($type);
        $this->entityManager->flush();
        $this->auditLogger->info('Document type created.', ['type_id' => $type->getId(), 'actor' => $this->actor()]);

        return $this->json($this->views->type($type), Response::HTTP_CREATED);
    }

    #[Route('/{id<\d+>}', name: 'update', methods: ['PATCH', 'PUT'])]
    #[IsGranted('ROLE_DMS_ADMIN')]
    public function update(int $id, #[MapRequestPayload] DocumentTypeInput $input): JsonResponse
    {
        $type = $this->typeRepository->find($id);
        if (!$type instanceof DocumentType) {
            return $this->json(['error' => 'Document type not found.'], Response::HTTP_NOT_FOUND);
        }
        if ($input->code !== null && strtoupper(trim($input->code)) !== $type->getCode()) {
            return $this->json(['error' => 'The type code is part of existing registration numbers and cannot be changed.'], Response::HTTP_BAD_REQUEST);
        }
        try {
            $this->apply($type, $input);
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['error' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        }
        $this->entityManager->flush();
        $this->auditLogger->info('Document type updated.', ['type_id' => $id, 'actor' => $this->actor()]);

        return $this->json($this->views->type($type));
    }

    private function apply(DocumentType $type, DocumentTypeInput $input): void
    {
        $type->rename($input->name ?? $type->getName(), $input->description ?? $type->getDescription());
        if ($input->number_pattern !== null) {
            $type->setNumberPattern($input->number_pattern);
        }
        $type->configure(
            $input->requires_approval ?? $type->requiresApproval(),
            $input->default_confidentiality ?? $type->getDefaultConfidentiality(),
            $input->clear_retention_years ? null : ($input->retention_years ?? $type->getRetentionYears()),
            $input->required_fields ?? $type->getRequiredFields(),
            $input->active ?? $type->isActive(),
        );
        $type->configureApproval(
            $input->route_template ?? $type->getRouteTemplate(),
            $input->auto_register ?? $type->autoRegisters(),
            $input->resubmit_mode ?? $type->getResubmitMode(),
        );
    }

    private function actor(): string
    {
        $user = $this->getUser();

        return $user instanceof User ? $user->getUserIdentifier() : 'anonymous';
    }
}
