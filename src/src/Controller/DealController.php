<?php

namespace App\Controller;

use App\Dto\DealInput;
use App\Dto\DealStageInput;
use App\Entity\Deal;
use App\Entity\DealStageChange;
use App\Entity\User;
use App\Repository\ClientRepository;
use App\Repository\DealRepository;
use App\Repository\DealStageChangeRepository;
use App\Repository\UserRepository;
use App\Service\InvoiceMoney;
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

#[Route('/api/deals', name: 'api_deals_')]
#[IsGranted('ROLE_USER')]
final class DealController extends AbstractController
{
    public function __construct(
        private readonly DealRepository $dealRepository,
        private readonly DealStageChangeRepository $stageChangeRepository,
        private readonly ClientRepository $clientRepository,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly InvoiceMoney $money,
        #[Autowire(service: 'monolog.logger.audit')] private readonly LoggerInterface $auditLogger,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $stage = $request->query->get('stage');
        if ($stage !== null && !isset(Deal::OPEN_STAGES[$stage]) && !in_array($stage, [Deal::WON, Deal::LOST], true)) {
            return $this->json(['error' => 'Unsupported deal stage.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        foreach (['client_id', 'owner_id'] as $key) {
            $value = $request->query->get($key);
            if ($value !== null && (!ctype_digit((string) $value) || (int) $value < 1)) {
                return $this->json(['error' => sprintf('%s must be a positive integer.', $key)], JsonResponse::HTTP_BAD_REQUEST);
            }
        }
        $clientId = $request->query->get('client_id');
        $ownerId = $request->query->get('owner_id');
        $term = $request->query->get('q');
        $deals = $this->dealRepository->search(
            $clientId !== null ? (int) $clientId : null,
            $stage !== null ? (string) $stage : null,
            $ownerId !== null ? (int) $ownerId : null,
            $term !== null ? trim((string) $term) : null,
        );

        return $this->json(array_map($this->serialize(...), $deals));
    }

    /** Open pipeline value per stage and currency; the weighted value applies each stage's fixed win probability. */
    #[Route('/summary', name: 'summary', methods: ['GET'])]
    public function summary(): JsonResponse
    {
        $stages = [];
        $totals = [];
        foreach ($this->dealRepository->findAll() as $deal) {
            $key = $deal->getStage();
            $stages[$key]['count'] = ($stages[$key]['count'] ?? 0) + 1;
            if ($deal->getAmountMinor() === null || $deal->getCurrency() === null) {
                $stages[$key]['unvalued'] = ($stages[$key]['unvalued'] ?? 0) + 1;
                continue;
            }
            $currency = $deal->getCurrency();
            $totals[$key][$currency]['amount'] = ($totals[$key][$currency]['amount'] ?? 0) + $deal->getAmountMinor();
            $totals[$key][$currency]['weighted'] = ($totals[$key][$currency]['weighted'] ?? 0) + intdiv($deal->getAmountMinor() * $deal->probability(), 100);
        }
        $result = [];
        foreach ([...array_keys(Deal::OPEN_STAGES), Deal::WON, Deal::LOST] as $stage) {
            $values = [];
            foreach ($totals[$stage] ?? [] as $currency => $sum) {
                $values[] = ['currency' => $currency, 'amount' => $this->money->formatMinor($sum['amount']), 'weighted_amount' => $this->money->formatMinor($sum['weighted'])];
            }
            $result[] = ['stage' => $stage, 'probability' => Deal::OPEN_STAGES[$stage] ?? ($stage === Deal::WON ? 100 : 0), 'count' => $stages[$stage]['count'] ?? 0, 'unvalued_count' => $stages[$stage]['unvalued'] ?? 0, 'values' => $values];
        }

        return $this->json(['stages' => $result, 'basis' => 'Operational forecast: fixed stage probabilities, no currency conversion; values are shown per currency.']);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(#[MapRequestPayload] DealInput $input): JsonResponse
    {
        if ($input->client_id === null || $input->title === null || trim($input->title) === '') {
            return $this->json(['error' => 'Client and title are required.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        $client = $this->clientRepository->find($input->client_id);
        if ($client === null) {
            return $this->json(['error' => 'Client not found.'], JsonResponse::HTTP_NOT_FOUND);
        }
        $deal = new Deal($client, $input->title);
        $error = $this->applyDescription($deal, $input, false);
        if ($error !== null) {
            return $this->json(['error' => $error], JsonResponse::HTTP_BAD_REQUEST);
        }
        $this->entityManager->persist($deal);
        $this->entityManager->persist(new DealStageChange($deal, null, $deal->getStage(), $this->actor(), null));
        $this->entityManager->flush();
        $this->auditLogger->info('Deal created.', ['deal_id' => $deal->getId(), 'client_id' => $client->getId(), 'actor' => $this->actor()]);

        return $this->json($this->serialize($deal), JsonResponse::HTTP_CREATED);
    }

    #[Route('/{id<\d+>}', name: 'show', methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        $deal = $this->dealRepository->find($id);

        return $deal instanceof Deal ? $this->json($this->serialize($deal)) : $this->json(['error' => 'Deal not found.'], JsonResponse::HTTP_NOT_FOUND);
    }

    #[Route('/{id<\d+>}', name: 'update', methods: ['PUT', 'PATCH'])]
    public function update(int $id, #[MapRequestPayload] DealInput $input): JsonResponse
    {
        $deal = $this->dealRepository->find($id);
        if (!$deal instanceof Deal) {
            return $this->json(['error' => 'Deal not found.'], JsonResponse::HTTP_NOT_FOUND);
        }
        if (!$deal->isOpen()) {
            return $this->json(['error' => 'A closed deal cannot be edited.'], JsonResponse::HTTP_CONFLICT);
        }
        $error = $this->applyDescription($deal, $input, true);
        if ($error !== null) {
            return $this->json(['error' => $error], JsonResponse::HTTP_BAD_REQUEST);
        }
        $this->entityManager->flush();

        return $this->json($this->serialize($deal));
    }

    #[Route('/{id<\d+>}/stage', name: 'stage', methods: ['POST'])]
    public function changeStage(int $id, #[MapRequestPayload] DealStageInput $input): JsonResponse
    {
        $deal = $this->dealRepository->find($id);
        if (!$deal instanceof Deal) {
            return $this->json(['error' => 'Deal not found.'], JsonResponse::HTTP_NOT_FOUND);
        }
        $from = $deal->getStage();
        $note = $input->note !== null && trim($input->note) !== '' ? trim($input->note) : null;
        try {
            $deal->moveTo((string) $input->stage, $note);
        } catch (\DomainException $exception) {
            return $this->json(['error' => $exception->getMessage()], JsonResponse::HTTP_CONFLICT);
        }
        $this->entityManager->persist(new DealStageChange($deal, $from, $deal->getStage(), $this->actor(), $note));
        $this->entityManager->flush();
        $this->auditLogger->info('Deal stage changed.', ['deal_id' => $id, 'from' => $from, 'to' => $deal->getStage(), 'actor' => $this->actor()]);

        return $this->json($this->serialize($deal));
    }

    #[Route('/{id<\d+>}/history', name: 'history', methods: ['GET'])]
    public function history(int $id): JsonResponse
    {
        if ($this->dealRepository->find($id) === null) {
            return $this->json(['error' => 'Deal not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        return $this->json(array_map(static fn (DealStageChange $change): array => [
            'from_stage' => $change->getFromStage(), 'to_stage' => $change->getToStage(), 'actor' => $change->getActor(),
            'note' => $change->getNote(), 'created_at' => $change->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ], $this->stageChangeRepository->findForDeal($id)));
    }

    #[Route('/{id<\d+>}', name: 'delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(int $id): Response
    {
        $deal = $this->dealRepository->find($id);
        if (!$deal instanceof Deal) {
            return $this->json(['error' => 'Deal not found.'], JsonResponse::HTTP_NOT_FOUND);
        }
        $this->entityManager->remove($deal);
        $this->entityManager->flush();
        $this->auditLogger->info('Deal deleted.', ['deal_id' => $id, 'actor' => $this->actor()]);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /** Applies description fields; on update, omitted fields keep their value unless a clear_* flag is set. */
    private function applyDescription(Deal $deal, DealInput $input, bool $partial): ?string
    {
        $title = $input->title !== null ? trim($input->title) : $deal->getTitle();
        $amountMinor = $deal->getAmountMinor();
        $currency = $deal->getCurrency();
        if ($input->clear_amount) {
            $amountMinor = $currency = null;
        }
        if ($input->amount !== null || $input->currency !== null) {
            $parsed = $input->amount !== null ? $this->money->parseMinor($input->amount) : $amountMinor;
            if ($input->amount !== null && $parsed === null) {
                return 'Deal value must be a non-negative amount with at most two decimal places.';
            }
            $amountMinor = $parsed;
            $currency = $input->currency ?? $currency;
        }
        $close = $deal->getExpectedCloseDate();
        if ($input->clear_close_date) {
            $close = null;
        } elseif ($input->expected_close_date !== null) {
            $close = \DateTimeImmutable::createFromFormat('!Y-m-d', $input->expected_close_date) ?: null;
            if ($close === null) {
                return 'Expected close date must use YYYY-MM-DD.';
            }
        }
        $owner = $deal->getOwner();
        if ($input->clear_owner) {
            $owner = null;
        } elseif ($input->owner_id !== null) {
            $owner = $this->userRepository->find($input->owner_id);
            if ($owner === null) {
                return 'Owner not found.';
            }
        }
        try {
            $deal->describe($title, $amountMinor, $currency, $close, $owner);
        } catch (\InvalidArgumentException $exception) {
            return $exception->getMessage();
        }

        return null;
    }

    private function actor(): string
    {
        $user = $this->getUser();

        return $user instanceof User ? $user->getUserIdentifier() : 'anonymous';
    }

    /** @return array<string, mixed> */
    private function serialize(Deal $deal): array
    {
        return [
            'id' => $deal->getId(),
            'client_id' => $deal->getClient()->getId(),
            'client_title' => $deal->getClient()->getTitle(),
            'title' => $deal->getTitle(),
            'stage' => $deal->getStage(),
            'probability' => $deal->probability(),
            'amount' => $deal->getAmountMinor() !== null ? $this->money->formatMinor($deal->getAmountMinor()) : null,
            'currency' => $deal->getCurrency(),
            'expected_close_date' => $deal->getExpectedCloseDate()?->format('Y-m-d'),
            'owner_id' => $deal->getOwner()?->getId(),
            'owner_email' => $deal->getOwner()?->getUserIdentifier(),
            'lost_reason' => $deal->getLostReason(),
            'closed_at' => $deal->getClosedAt()?->format(\DateTimeInterface::ATOM),
            'created_at' => $deal->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $deal->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
