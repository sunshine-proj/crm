<?php

namespace App\Controller;

use App\Dto\PurchaseApprovalPolicyInput;
use App\Dto\PurchaseOrderDecisionInput;
use App\Entity\OrganizationSettings;
use App\Entity\PurchaseOrder;
use App\Entity\PurchaseOrderApproval;
use App\Entity\User;
use App\Repository\OrganizationSettingsRepository;
use App\Repository\PurchaseOrderApprovalRepository;
use App\Repository\PurchaseOrderRepository;
use App\Service\InvoiceMoney;
use App\Service\PurchaseApprovalPolicy;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Purchase-order approvals: a finance user other than the creator approves or rejects orders above the threshold. */
#[Route('/api/inventory', name: 'api_purchasing_')]
#[IsGranted('ROLE_USER')]
final class PurchaseApprovalController extends AbstractController
{
    public function __construct(
        private readonly PurchaseOrderRepository $orderRepository,
        private readonly PurchaseOrderApprovalRepository $approvalRepository,
        private readonly OrganizationSettingsRepository $settingsRepository,
        private readonly PurchaseApprovalPolicy $policy,
        private readonly EntityManagerInterface $entityManager,
        private readonly InvoiceMoney $money,
        #[Autowire(service: 'monolog.logger.audit')] private readonly LoggerInterface $auditLogger,
    ) {
    }

    #[Route('/purchase-approval-policy', name: 'policy_show', methods: ['GET'])]
    #[IsGranted('ROLE_FINANCE')]
    public function policy(): JsonResponse
    {
        return $this->json($this->serializePolicy());
    }

    #[Route('/purchase-approval-policy', name: 'policy_update', methods: ['PUT'])]
    #[IsGranted('ROLE_ADMIN')]
    public function updatePolicy(#[MapRequestPayload] PurchaseApprovalPolicyInput $input): JsonResponse
    {
        $threshold = null;
        if ($input->threshold !== null && trim($input->threshold) !== '') {
            $threshold = $this->money->parseMinor($input->threshold);
            if ($threshold === null) {
                return $this->json(['error' => 'Threshold must be a non-negative amount with at most two decimal places.'], JsonResponse::HTTP_BAD_REQUEST);
            }
        }
        $settings = $this->settingsRepository->find(1);
        if (!$settings instanceof OrganizationSettings) {
            $settings = new OrganizationSettings();
            $this->entityManager->persist($settings);
        }
        $settings->setPurchaseApprovalThresholdMinor($threshold);
        $this->entityManager->flush();
        $this->auditLogger->info('Purchase approval threshold changed.', ['threshold_minor' => $threshold, 'actor' => $this->actorName()]);

        return $this->json($this->serializePolicy());
    }

    #[Route('/purchase-orders/{id<\d+>}/approve', name: 'approve', methods: ['POST'])]
    #[IsGranted('ROLE_FINANCE')]
    public function approve(int $id, #[MapRequestPayload] PurchaseOrderDecisionInput $input): JsonResponse
    {
        return $this->decide($id, 'approved', $input);
    }

    #[Route('/purchase-orders/{id<\d+>}/reject', name: 'reject', methods: ['POST'])]
    #[IsGranted('ROLE_FINANCE')]
    public function reject(int $id, #[MapRequestPayload] PurchaseOrderDecisionInput $input): JsonResponse
    {
        if ($input->comment === null || trim($input->comment) === '') {
            return $this->json(['error' => 'A comment is required to reject a purchase order.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        return $this->decide($id, 'rejected', $input);
    }

    #[Route('/purchase-orders/{id<\d+>}/approvals', name: 'history', methods: ['GET'])]
    public function history(int $id): JsonResponse
    {
        if ($this->orderRepository->find($id) === null) {
            return $this->json(['error' => 'Purchase order not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        return $this->json(array_map(static fn (PurchaseOrderApproval $approval): array => [
            'decision' => $approval->getDecision(), 'actor' => $approval->getActor()->getUserIdentifier(),
            'comment' => $approval->getComment(), 'created_at' => $approval->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ], $this->approvalRepository->forOrder($id)));
    }

    private function decide(int $id, string $decision, PurchaseOrderDecisionInput $input): JsonResponse
    {
        $order = $this->orderRepository->find($id);
        if (!$order instanceof PurchaseOrder) {
            return $this->json(['error' => 'Purchase order not found.'], JsonResponse::HTTP_NOT_FOUND);
        }
        $actor = $this->getUser();
        if (!$actor instanceof User) {
            return $this->json(['error' => 'Authenticated user is unavailable.'], JsonResponse::HTTP_UNAUTHORIZED);
        }
        if ($order->getStatus() !== 'pending_approval') {
            return $this->json(['error' => 'Only purchase orders awaiting approval can be decided.'], JsonResponse::HTTP_CONFLICT);
        }
        if ($order->getCreatedBy()?->getId() === $actor->getId() && !$this->isGranted('ROLE_ADMIN')) {
            return $this->json(['error' => 'You cannot decide a purchase order you created.'], JsonResponse::HTTP_FORBIDDEN);
        }
        $comment = $input->comment !== null && trim($input->comment) !== '' ? trim($input->comment) : null;
        $this->entityManager->persist(new PurchaseOrderApproval($order, $this->entityManager->getReference(User::class, $actor->getId()), $decision, $comment));
        $order->setStatus($decision === 'approved' ? 'open' : 'rejected');
        $this->entityManager->flush();
        $this->auditLogger->info('Purchase order decision recorded.', ['purchase_order_id' => $id, 'decision' => $decision, 'actor' => $actor->getUserIdentifier()]);

        return $this->json(['id' => $order->getId(), 'order_number' => $order->getOrderNumber(), 'status' => $order->getStatus()]);
    }

    private function actorName(): ?string
    {
        $user = $this->getUser();

        return $user instanceof User ? $user->getUserIdentifier() : null;
    }

    /** @return array<string, mixed> */
    private function serializePolicy(): array
    {
        $threshold = $this->policy->thresholdMinor();

        return [
            'threshold' => $threshold !== null ? $this->money->formatMinor($threshold) : null,
            'enabled' => $threshold !== null,
            'basis' => 'Orders in the default currency at or above the threshold, and every order in another currency, need approval by a finance user other than the creator (administrators may approve their own). Without a threshold, no approval is required.',
        ];
    }
}
