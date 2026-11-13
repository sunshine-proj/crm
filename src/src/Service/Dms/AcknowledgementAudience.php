<?php

namespace App\Service\Dms;

use App\Entity\AcknowledgementRecipient;
use App\Entity\AcknowledgementRequest;
use App\Entity\DocumentEvent;
use App\Entity\OrgUnit;
use App\Entity\Position;
use App\Entity\User;
use App\Repository\AcknowledgementRecipientRepository;
use App\Repository\AcknowledgementRequestRepository;
use App\Repository\PositionRepository;
use App\Repository\UserRepository;
use App\Service\Org\OrgChart;
use App\Service\Org\SubordinationService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Turns acknowledgement targets (units with or without sub-units, positions, people) into the people to ask, with
 * the unit and position snapshot each one is recorded under. Also adds people who joined a targeted unit or
 * position to open requests that ask for it.
 *
 * Scope: a sender may target only units in their management subtree (positions in those units, people employed
 * there); `null` scope means the whole organization (registry clerks).
 */
final class AcknowledgementAudience
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PositionRepository $positionRepository,
        private readonly UserRepository $userRepository,
        private readonly AcknowledgementRequestRepository $requestRepository,
        private readonly AcknowledgementRecipientRepository $recipientRepository,
        private readonly DmsNotifier $notifier,
        private readonly SubordinationService $subordination,
    ) {
    }

    /**
     * Validates raw targets against the scope and gives them stored labels.
     *
     * @param list<int>|null $scope unit ids the actor may target; null for the whole organization
     * @return list<array{kind: string, id: int, include_subunits: bool, label: string}>
     */
    public function normalize(mixed $input, ?array $scope, OrgChart $chart): array
    {
        if (!is_array($input) || $input === []) {
            throw new \InvalidArgumentException('Choose at least one unit, position or person.');
        }
        if (count($input) > AcknowledgementRequest::MAX_TARGETS) {
            throw new \InvalidArgumentException(sprintf('Choose at most %d units, positions and people at once.', AcknowledgementRequest::MAX_TARGETS));
        }
        $targets = [];
        foreach ($input as $raw) {
            $kind = is_array($raw) ? ($raw['kind'] ?? null) : null;
            $id = is_array($raw) ? ($raw['id'] ?? null) : null;
            if (!in_array($kind, AcknowledgementRequest::TARGET_KINDS, true) || !is_int($id) || $id < 1) {
                throw new \InvalidArgumentException('Each recipient choice needs a kind (unit, position or user) and an id.');
            }
            $targets[] = match ($kind) {
                AcknowledgementRequest::TARGET_UNIT => $this->unitTarget($id, ($raw['include_subunits'] ?? false) === true, $scope, $chart),
                AcknowledgementRequest::TARGET_POSITION => $this->positionTarget($id, $scope),
                default => $this->userTarget($id, $scope, $chart),
            };
        }

        return $targets;
    }

    /**
     * The people the targets cover today, each with the employment that put them on the list.
     *
     * @param list<array{kind: string, id: int, include_subunits: bool, label: string}> $targets
     * @return array<int, array{user: User, unit: ?OrgUnit, position: ?string}> user id => snapshot, first target wins
     */
    public function resolve(array $targets, OrgChart $chart): array
    {
        $people = [];
        $add = static function (User $user, ?OrgUnit $unit, ?string $position) use (&$people): void {
            $people[(int) $user->getId()] ??= ['user' => $user, 'unit' => $unit, 'position' => $position];
        };
        foreach ($targets as $target) {
            if ($target['kind'] === AcknowledgementRequest::TARGET_UNIT) {
                $unitIds = $target['include_subunits'] ? $chart->subtreeIds($target['id']) : [$target['id']];
                foreach ($chart->members($target['id'], $target['include_subunits']) as $user) {
                    $employment = $chart->employmentIn($user, $unitIds);
                    $add($user, $employment?->getPosition()->getUnit(), $employment?->getPosition()->getTitle());
                }
            } elseif ($target['kind'] === AcknowledgementRequest::TARGET_POSITION) {
                foreach ($chart->holdersOf($target['id']) as $employment) {
                    $add($employment->getUser(), $employment->getPosition()->getUnit(), $employment->getPosition()->getTitle());
                }
            } else {
                $user = $this->userRepository->find($target['id']);
                if ($user instanceof User) {
                    $employment = $chart->primaryEmployment($user);
                    $add($user, $employment?->getPosition()->getUnit(), $employment?->getPosition()->getTitle());
                }
            }
        }

        return $people;
    }

    /**
     * Puts the people on the request unless they are already on it or are its sender. Persists, does not flush.
     *
     * @param array<int, array{user: User, unit: ?OrgUnit, position: ?string}> $people
     * @return list<AcknowledgementRecipient>
     */
    public function enlist(AcknowledgementRequest $request, array $people, string $source, string $addedBy): array
    {
        $existing = $request->getId() !== null ? $this->recipientRepository->userIdsOn($request) : [];
        $added = [];
        foreach ($people as $userId => $person) {
            if (isset($existing[$userId]) || $userId === $request->getSender()->getId()) {
                continue;
            }
            $recipient = new AcknowledgementRecipient($request, $person['user'], $person['unit'], $person['position'], $source, $addedBy);
            $this->entityManager->persist($recipient);
            $added[] = $recipient;
        }

        return $added;
    }

    /**
     * Notifies newly enlisted recipients. Call after the recipients were flushed (the dedupe key needs ids).
     *
     * @param list<AcknowledgementRecipient> $recipients
     */
    public function announce(AcknowledgementRequest $request, array $recipients): void
    {
        $document = $request->getDocument();
        $due = $request->getDueOn() !== null ? ' by '.$request->getDueOn()->format('Y-m-d') : '';
        foreach ($recipients as $recipient) {
            $this->notifier->acknowledgement($recipient->getUser(), $document, sprintf('Please read and acknowledge "%s"%s.', $document->getTitle(), $due), sprintf('ack:%d:user:%d', $request->getId(), $recipient->getUser()->getId()));
        }
    }

    /**
     * Adds people now holding a post in a targeted unit or position to open requests that take in joiners.
     * Individually named people are not re-resolved. Safe to run repeatedly.
     *
     * @return int people added
     */
    public function addJoiners(?\DateTimeImmutable $day = null): int
    {
        $requests = $this->requestRepository->findOpenAddingJoiners();
        if ($requests === []) {
            return 0;
        }
        $chart = $this->subordination->chartOn($day);
        $count = 0;
        foreach ($requests as $request) {
            $targets = array_values(array_filter($request->getTargets(), static fn (array $target): bool => $target['kind'] !== AcknowledgementRequest::TARGET_USER));
            $added = $this->enlist($request, $this->resolve($targets, $chart), AcknowledgementRecipient::JOINED, 'automatic (joined)');
            if ($added === []) {
                continue;
            }
            $this->entityManager->flush();
            $this->announce($request, $added);
            $this->entityManager->persist(new DocumentEvent($request->getDocument(), 'automatic (joined)', 'acknowledgement_recipients_added', ['request' => $request->getId(), 'count' => count($added)]));
            $this->entityManager->flush();
            $count += count($added);
        }

        return $count;
    }

    /** @param list<int>|null $scope */
    private function unitTarget(int $id, bool $includeSubunits, ?array $scope, OrgChart $chart): array
    {
        $unit = $chart->unit($id) ?? throw new NotFound('Unit not found.');
        if ($unit->isArchived()) {
            throw new \DomainException(sprintf('%s is archived.', $unit->getName()));
        }
        $this->assertInScope($id, $scope, $unit->getName());

        return ['kind' => AcknowledgementRequest::TARGET_UNIT, 'id' => $id, 'include_subunits' => $includeSubunits, 'label' => $unit->getName().($includeSubunits ? ' (with sub-units)' : '')];
    }

    /** @param list<int>|null $scope */
    private function positionTarget(int $id, ?array $scope): array
    {
        $position = $this->positionRepository->find($id);
        if (!$position instanceof Position) {
            throw new NotFound('Position not found.');
        }
        if ($position->isArchived() || $position->getUnit()->isArchived()) {
            throw new \DomainException(sprintf('The position %s is archived.', $position->getTitle()));
        }
        $this->assertInScope((int) $position->getUnit()->getId(), $scope, $position->getUnit()->getName());

        return ['kind' => AcknowledgementRequest::TARGET_POSITION, 'id' => $id, 'include_subunits' => false, 'label' => $position->getTitle().', '.$position->getUnit()->getName()];
    }

    /** @param list<int>|null $scope */
    private function userTarget(int $id, ?array $scope, OrgChart $chart): array
    {
        $user = $this->userRepository->find($id);
        if (!$user instanceof User) {
            throw new NotFound('Person not found.');
        }
        if ($scope !== null && $chart->employmentIn($user, $scope) === null) {
            throw new \DomainException(sprintf('%s does not work in a unit you manage.', $user->getUserIdentifier()));
        }

        return ['kind' => AcknowledgementRequest::TARGET_USER, 'id' => $id, 'include_subunits' => false, 'label' => $user->getUserIdentifier()];
    }

    /** @param list<int>|null $scope */
    private function assertInScope(int $unitId, ?array $scope, string $name): void
    {
        if ($scope !== null && !in_array($unitId, $scope, true)) {
            throw new \DomainException(sprintf('%s is outside the units you manage.', $name));
        }
    }
}
