<?php

namespace App\Controller;

use App\Dto\DelegationInput;
use App\Entity\Delegation;
use App\Entity\User;
use App\Repository\DelegationRepository;
use App\Repository\UserRepository;
use App\Service\Org\OrgViews;
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

/**
 * Deputies (substitution). A user names a deputy for their own absence; an administrator may record one for
 * anybody. Only the principal or an administrator can revoke it. Users see delegations they gave or received;
 * administrators can list all.
 */
#[Route('/api/delegations', name: 'api_delegations_')]
#[IsGranted('ROLE_USER')]
final class DelegationController extends AbstractController
{
    public function __construct(
        private readonly DelegationRepository $delegationRepository,
        private readonly UserRepository $userRepository,
        private readonly OrgViews $views,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire(service: 'monolog.logger.audit')] private readonly LoggerInterface $auditLogger,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $all = $request->query->getBoolean('all');
        if ($all && !$this->isGranted('ROLE_ADMIN')) {
            return $this->json(['error' => 'Only administrators can list every delegation.'], Response::HTTP_FORBIDDEN);
        }
        $today = new \DateTimeImmutable('today');

        return $this->json(array_map(
            fn (Delegation $delegation): array => $this->views->delegation($delegation, $today),
            $this->delegationRepository->findInvolving($all ? null : $this->currentUser(), $request->query->getBoolean('include_past'), $today),
        ));
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(#[MapRequestPayload] DelegationInput $input): JsonResponse
    {
        $actor = $this->currentUser();
        $principal = $actor;
        if ($input->principal_id !== null && $input->principal_id !== $actor->getId()) {
            if (!$this->isGranted('ROLE_ADMIN')) {
                return $this->json(['error' => 'You can only name a deputy for yourself.'], Response::HTTP_FORBIDDEN);
            }
            $principal = $this->userRepository->find($input->principal_id);
            if (!$principal instanceof User) {
                return $this->json(['error' => 'Principal not found.'], Response::HTTP_NOT_FOUND);
            }
        }
        if ($input->deputy_id === null) {
            return $this->json(['error' => 'A deputy is required.'], Response::HTTP_BAD_REQUEST);
        }
        $deputy = $this->userRepository->find($input->deputy_id);
        if (!$deputy instanceof User) {
            return $this->json(['error' => 'Deputy not found.'], Response::HTTP_NOT_FOUND);
        }
        $startsOn = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $input->starts_on);
        $endsOn = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $input->ends_on);
        if ($startsOn === false || $endsOn === false) {
            return $this->json(['error' => 'Dates must use YYYY-MM-DD.'], Response::HTTP_BAD_REQUEST);
        }
        if ($endsOn < new \DateTimeImmutable('today')) {
            return $this->json(['error' => 'A delegation must not end in the past.'], Response::HTTP_BAD_REQUEST);
        }
        try {
            $delegation = new Delegation($principal, $deputy, $startsOn, $endsOn, $input->reason, $actor->getUserIdentifier());
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['error' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        }
        if ($this->delegationRepository->hasOverlap($principal, $startsOn, $endsOn)) {
            return $this->json(['error' => 'A deputy is already named for part of that period; revoke it first.'], Response::HTTP_CONFLICT);
        }
        $this->entityManager->persist($delegation);
        $this->entityManager->flush();
        $this->auditLogger->info('Delegation created.', ['delegation_id' => $delegation->getId(), 'principal_id' => $principal->getId(), 'deputy_id' => $deputy->getId(), 'actor' => $actor->getUserIdentifier()]);

        return $this->json($this->views->delegation($delegation, new \DateTimeImmutable('today')), Response::HTTP_CREATED);
    }

    #[Route('/{id<\d+>}/revoke', name: 'revoke', methods: ['POST'])]
    public function revoke(int $id): JsonResponse
    {
        $delegation = $this->delegationRepository->find($id);
        if (!$delegation instanceof Delegation) {
            return $this->json(['error' => 'Delegation not found.'], Response::HTTP_NOT_FOUND);
        }
        $actor = $this->currentUser();
        if ($delegation->getPrincipal()->getId() !== $actor->getId() && !$this->isGranted('ROLE_ADMIN')) {
            return $this->json(['error' => 'Only the principal or an administrator can revoke this delegation.'], Response::HTTP_FORBIDDEN);
        }
        try {
            $delegation->revoke($actor->getUserIdentifier());
        } catch (\DomainException $exception) {
            return $this->json(['error' => $exception->getMessage()], Response::HTTP_CONFLICT);
        }
        $this->entityManager->flush();
        $this->auditLogger->info('Delegation revoked.', ['delegation_id' => $id, 'actor' => $actor->getUserIdentifier()]);

        return $this->json($this->views->delegation($delegation, new \DateTimeImmutable('today')));
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
