<?php

namespace App\Controller;

use App\Dto\EmploymentInput;
use App\Dto\OrgUnitInput;
use App\Dto\PositionInput;
use App\Entity\Employment;
use App\Entity\OrgUnit;
use App\Entity\Position;
use App\Entity\User;
use App\Repository\EmploymentRepository;
use App\Repository\OrgUnitRepository;
use App\Repository\PositionRepository;
use App\Repository\UserRepository;
use App\Service\Org\OrgChart;
use App\Service\Org\OrgStructureRules;
use App\Service\Org\OrgViews;
use App\Service\Org\SubordinationService;
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
 * Organization structure: units, positions, employments and the reporting lines derived from them. Everyone can
 * read the structure (it drives approval routing and acknowledgement lists); only administrators change it.
 */
#[Route('/api/org', name: 'api_org_')]
#[IsGranted('ROLE_USER')]
final class OrgController extends AbstractController
{
    public function __construct(
        private readonly OrgUnitRepository $unitRepository,
        private readonly PositionRepository $positionRepository,
        private readonly EmploymentRepository $employmentRepository,
        private readonly UserRepository $userRepository,
        private readonly SubordinationService $subordination,
        private readonly OrgStructureRules $rules,
        private readonly OrgViews $views,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire(service: 'monolog.logger.audit')] private readonly LoggerInterface $auditLogger,
    ) {
    }

    #[Route('/units', name: 'units_list', methods: ['GET'])]
    public function units(): JsonResponse
    {
        $chart = $this->subordination->chartOn();

        return $this->json(array_map(fn (OrgUnit $unit): array => $this->views->unit($unit, $chart), $this->unitRepository->findAllOrdered()));
    }

    #[Route('/units', name: 'units_create', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function createUnit(#[MapRequestPayload] OrgUnitInput $input): JsonResponse
    {
        if ($input->code === null || $input->name === null || $input->kind === null) {
            return $this->error('Code, name and kind are required.', Response::HTTP_BAD_REQUEST);
        }
        $parent = null;
        if ($input->parent_id !== null && !($parent = $this->unitRepository->find($input->parent_id)) instanceof OrgUnit) {
            return $this->error('Parent unit not found.', Response::HTTP_NOT_FOUND);
        }
        if ($this->codeTaken($input->code, null)) {
            return $this->error('Another unit already uses this code.', Response::HTTP_CONFLICT);
        }
        try {
            $unit = new OrgUnit($input->code, $input->name, $input->kind, $parent);
        } catch (\InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_BAD_REQUEST);
        } catch (\DomainException $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_CONFLICT);
        }
        $this->entityManager->persist($unit);
        $this->entityManager->flush();
        $this->auditLogger->info('Org unit created.', ['unit_id' => $unit->getId(), 'actor' => $this->actorName()]);

        return $this->json($this->views->unit($unit, $this->subordination->chartOn()), Response::HTTP_CREATED);
    }

    #[Route('/units/{id<\d+>}', name: 'units_update', methods: ['PATCH', 'PUT'])]
    #[IsGranted('ROLE_ADMIN')]
    public function updateUnit(int $id, #[MapRequestPayload] OrgUnitInput $input): JsonResponse
    {
        $unit = $this->unitRepository->find($id);
        if (!$unit instanceof OrgUnit) {
            return $this->error('Unit not found.', Response::HTTP_NOT_FOUND);
        }
        $parent = $unit->getParent();
        if ($input->clear_parent) {
            $parent = null;
        } elseif ($input->parent_id !== null && !($parent = $this->unitRepository->find($input->parent_id)) instanceof OrgUnit) {
            return $this->error('Parent unit not found.', Response::HTTP_NOT_FOUND);
        }
        $code = $input->code ?? $unit->getCode();
        if ($this->codeTaken($code, $unit)) {
            return $this->error('Another unit already uses this code.', Response::HTTP_CONFLICT);
        }
        try {
            if ($parent !== $unit->getParent()) {
                $this->rules->assertSubtreeFits($unit, $parent);
            }
            $unit->define($code, $input->name ?? $unit->getName(), $input->kind ?? $unit->getKind(), $parent);
        } catch (\InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_BAD_REQUEST);
        } catch (\DomainException $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_CONFLICT);
        }
        $this->entityManager->flush();
        $this->auditLogger->info('Org unit updated.', ['unit_id' => $id, 'actor' => $this->actorName()]);

        return $this->json($this->views->unit($unit, $this->subordination->chartOn()));
    }

    #[Route('/units/{id<\d+>}/archive', name: 'units_archive', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function archiveUnit(int $id): JsonResponse
    {
        return $this->changeUnit($id, function (OrgUnit $unit): void {
            $this->rules->assertUnitCanBeArchived($unit);
            $unit->archive();
        }, 'Org unit archived.');
    }

    #[Route('/units/{id<\d+>}/restore', name: 'units_restore', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function restoreUnit(int $id): JsonResponse
    {
        return $this->changeUnit($id, static fn (OrgUnit $unit) => $unit->restore(), 'Org unit restored.');
    }

    #[Route('/units/{id<\d+>}', name: 'units_delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADMIN')]
    public function deleteUnit(int $id): Response
    {
        $unit = $this->unitRepository->find($id);
        if (!$unit instanceof OrgUnit) {
            return $this->error('Unit not found.', Response::HTTP_NOT_FOUND);
        }
        try {
            $this->rules->assertUnitCanBeDeleted($unit);
        } catch (\DomainException $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_CONFLICT);
        }
        $this->entityManager->remove($unit);
        $this->entityManager->flush();
        $this->auditLogger->info('Org unit deleted.', ['unit_id' => $id, 'actor' => $this->actorName()]);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /** Everyone holding a post in the unit today, optionally with its sub-units. */
    #[Route('/units/{id<\d+>}/members', name: 'units_members', methods: ['GET'])]
    public function members(int $id, Request $request): JsonResponse
    {
        if ($this->unitRepository->find($id) === null) {
            return $this->error('Unit not found.', Response::HTTP_NOT_FOUND);
        }
        $chart = $this->subordination->chartOn();

        return $this->json($this->views->people($chart->members($id, $request->query->getBoolean('include_subunits')), $chart));
    }

    #[Route('/positions', name: 'positions_list', methods: ['GET'])]
    public function positions(Request $request): JsonResponse
    {
        $unitId = $this->positiveQueryInt($request, 'unit_id');
        if ($unitId === false) {
            return $this->error('unit_id must be a positive integer.', Response::HTTP_BAD_REQUEST);
        }
        $holders = [];
        foreach ($this->employmentRepository->findActiveOn(new \DateTimeImmutable('today')) as $employment) {
            $holders[(int) $employment->getPosition()->getId()][] = $employment;
        }

        return $this->json(array_map(
            fn (Position $position): array => $this->views->position($position, $holders[(int) $position->getId()] ?? []),
            $this->positionRepository->findAllOrdered($unitId),
        ));
    }

    #[Route('/positions', name: 'positions_create', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function createPosition(#[MapRequestPayload] PositionInput $input): JsonResponse
    {
        if ($input->unit_id === null || $input->title === null) {
            return $this->error('Unit and title are required.', Response::HTTP_BAD_REQUEST);
        }
        $unit = $this->unitRepository->find($input->unit_id);
        if (!$unit instanceof OrgUnit) {
            return $this->error('Unit not found.', Response::HTTP_NOT_FOUND);
        }
        try {
            if ($input->is_head === true) {
                $this->rules->assertSingleHead($unit);
            }
            $position = new Position($unit, $input->title, $input->is_head ?? false);
        } catch (\InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_BAD_REQUEST);
        } catch (\DomainException $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_CONFLICT);
        }
        $this->entityManager->persist($position);
        $this->entityManager->flush();
        $this->auditLogger->info('Position created.', ['position_id' => $position->getId(), 'unit_id' => $unit->getId(), 'actor' => $this->actorName()]);

        return $this->json($this->views->position($position), Response::HTTP_CREATED);
    }

    #[Route('/positions/{id<\d+>}', name: 'positions_update', methods: ['PATCH', 'PUT'])]
    #[IsGranted('ROLE_ADMIN')]
    public function updatePosition(int $id, #[MapRequestPayload] PositionInput $input): JsonResponse
    {
        $position = $this->positionRepository->find($id);
        if (!$position instanceof Position) {
            return $this->error('Position not found.', Response::HTTP_NOT_FOUND);
        }
        if ($input->unit_id !== null && $input->unit_id !== $position->getUnit()->getId()) {
            return $this->error('A position cannot be moved to another unit; create a new position instead.', Response::HTTP_BAD_REQUEST);
        }
        $isHead = $input->is_head ?? $position->isHead();
        try {
            if ($isHead && !$position->isHead() && !$position->isArchived()) {
                $this->rules->assertSingleHead($position->getUnit(), $position);
            }
            $position->describe($input->title ?? $position->getTitle(), $isHead);
        } catch (\InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_BAD_REQUEST);
        } catch (\DomainException $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_CONFLICT);
        }
        $this->entityManager->flush();
        $this->auditLogger->info('Position updated.', ['position_id' => $id, 'actor' => $this->actorName()]);

        return $this->json($this->views->position($position));
    }

    #[Route('/positions/{id<\d+>}/archive', name: 'positions_archive', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function archivePosition(int $id): JsonResponse
    {
        $position = $this->positionRepository->find($id);
        if (!$position instanceof Position) {
            return $this->error('Position not found.', Response::HTTP_NOT_FOUND);
        }
        try {
            $this->rules->assertPositionCanBeArchived($position);
            $position->archive();
        } catch (\DomainException $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_CONFLICT);
        }
        $this->entityManager->flush();
        $this->auditLogger->info('Position archived.', ['position_id' => $id, 'actor' => $this->actorName()]);

        return $this->json($this->views->position($position));
    }

    #[Route('/positions/{id<\d+>}', name: 'positions_delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADMIN')]
    public function deletePosition(int $id): Response
    {
        $position = $this->positionRepository->find($id);
        if (!$position instanceof Position) {
            return $this->error('Position not found.', Response::HTTP_NOT_FOUND);
        }
        try {
            $this->rules->assertPositionCanBeDeleted($position);
        } catch (\DomainException $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_CONFLICT);
        }
        $this->entityManager->remove($position);
        $this->entityManager->flush();
        $this->auditLogger->info('Position deleted.', ['position_id' => $id, 'actor' => $this->actorName()]);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/employments', name: 'employments_list', methods: ['GET'])]
    public function employments(Request $request): JsonResponse
    {
        $unitId = $this->positiveQueryInt($request, 'unit_id');
        $userId = $this->positiveQueryInt($request, 'user_id');
        if ($unitId === false || $userId === false) {
            return $this->error('unit_id and user_id must be positive integers.', Response::HTTP_BAD_REQUEST);
        }
        $today = new \DateTimeImmutable('today');

        return $this->json(array_map(
            fn (Employment $employment): array => $this->views->employment($employment, $today),
            $this->employmentRepository->search($unitId, $userId, $request->query->getBoolean('include_ended'), $today),
        ));
    }

    #[Route('/employments', name: 'employments_create', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function createEmployment(#[MapRequestPayload] EmploymentInput $input): JsonResponse
    {
        if ($input->user_id === null || $input->position_id === null || $input->starts_on === null) {
            return $this->error('User, position and start date are required.', Response::HTTP_BAD_REQUEST);
        }
        $user = $this->userRepository->find($input->user_id);
        $position = $this->positionRepository->find($input->position_id);
        if (!$user instanceof User || !$position instanceof Position) {
            return $this->error($user instanceof User ? 'Position not found.' : 'User not found.', Response::HTTP_NOT_FOUND);
        }
        $reportsTo = null;
        if ($input->reports_to_id !== null && !($reportsTo = $this->userRepository->find($input->reports_to_id)) instanceof User) {
            return $this->error('Reporting superior not found.', Response::HTTP_NOT_FOUND);
        }
        try {
            $employment = new Employment($user, $position, $this->date($input->starts_on), $input->is_primary ?? true, $input->is_acting ?? false);
            $employment->endOn($input->ends_on !== null ? $this->date($input->ends_on) : null);
            $employment->reportTo($reportsTo);
            $this->rules->assertEmploymentFits($employment);
            if ($employment->isPrimary()) {
                $this->rules->assertNoReportingCycle($user, $reportsTo);
            }
        } catch (\InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_BAD_REQUEST);
        } catch (\DomainException $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_CONFLICT);
        }
        $this->entityManager->persist($employment);
        $this->entityManager->flush();
        $this->auditLogger->info('Employment created.', ['employment_id' => $employment->getId(), 'user_id' => $user->getId(), 'position_id' => $position->getId(), 'actor' => $this->actorName()]);

        return $this->json($this->views->employment($employment, new \DateTimeImmutable('today')), Response::HTTP_CREATED);
    }

    #[Route('/employments/{id<\d+>}', name: 'employments_update', methods: ['PATCH', 'PUT'])]
    #[IsGranted('ROLE_ADMIN')]
    public function updateEmployment(int $id, #[MapRequestPayload] EmploymentInput $input): JsonResponse
    {
        $employment = $this->employmentRepository->find($id);
        if (!$employment instanceof Employment) {
            return $this->error('Employment not found.', Response::HTTP_NOT_FOUND);
        }
        if (($input->user_id !== null && $input->user_id !== $employment->getUser()->getId())
            || ($input->position_id !== null && $input->position_id !== $employment->getPosition()->getId())
            || ($input->starts_on !== null && $input->starts_on !== $employment->getStartsOn()->format('Y-m-d'))) {
            return $this->error('Employee, position and start date cannot be changed; end this employment and record a new one.', Response::HTTP_BAD_REQUEST);
        }
        $reportsTo = $employment->getReportsTo();
        if ($input->clear_reports_to) {
            $reportsTo = null;
        } elseif ($input->reports_to_id !== null && !($reportsTo = $this->userRepository->find($input->reports_to_id)) instanceof User) {
            return $this->error('Reporting superior not found.', Response::HTTP_NOT_FOUND);
        }
        try {
            if ($input->clear_ends_on) {
                $employment->endOn(null);
            } elseif ($input->ends_on !== null) {
                $employment->endOn($this->date($input->ends_on));
            }
            $employment->classify($input->is_primary ?? $employment->isPrimary(), $input->is_acting ?? $employment->isActing());
            if ($reportsTo !== $employment->getReportsTo()) {
                $this->rules->assertNoReportingCycle($employment->getUser(), $reportsTo);
                $employment->reportTo($reportsTo);
            }
            $this->rules->assertEmploymentFits($employment);
        } catch (\InvalidArgumentException $exception) {
            $this->entityManager->refresh($employment);

            return $this->error($exception->getMessage(), Response::HTTP_BAD_REQUEST);
        } catch (\DomainException $exception) {
            $this->entityManager->refresh($employment);

            return $this->error($exception->getMessage(), Response::HTTP_CONFLICT);
        }
        $this->entityManager->flush();
        $this->auditLogger->info('Employment updated.', ['employment_id' => $id, 'actor' => $this->actorName()]);

        return $this->json($this->views->employment($employment, new \DateTimeImmutable('today')));
    }

    /** Removes an employment recorded by mistake; ending it (ends_on) is the normal way to record a change. */
    #[Route('/employments/{id<\d+>}', name: 'employments_delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADMIN')]
    public function deleteEmployment(int $id): Response
    {
        $employment = $this->employmentRepository->find($id);
        if (!$employment instanceof Employment) {
            return $this->error('Employment not found.', Response::HTTP_NOT_FOUND);
        }
        $this->entityManager->remove($employment);
        $this->entityManager->flush();
        $this->auditLogger->info('Employment deleted.', ['employment_id' => $id, 'actor' => $this->actorName()]);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /** Minimal person selector: every user with their current primary position and unit. */
    #[Route('/people', name: 'people', methods: ['GET'])]
    public function people(): JsonResponse
    {
        $chart = $this->subordination->chartOn();

        return $this->json($this->views->people($this->userRepository->findBy([], ['email' => 'ASC']), $chart));
    }

    #[Route('/me', name: 'me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->error('Not signed in.', Response::HTTP_UNAUTHORIZED);
        }

        return $this->json($this->reportingLine($user, $this->subordination->chartOn()));
    }

    #[Route('/users/{id<\d+>}/reporting-line', name: 'reporting_line', methods: ['GET'])]
    public function userReportingLine(int $id): JsonResponse
    {
        $user = $this->userRepository->find($id);
        if (!$user instanceof User) {
            return $this->error('User not found.', Response::HTTP_NOT_FOUND);
        }

        return $this->json($this->reportingLine($user, $this->subordination->chartOn()));
    }

    /** @return array<string, mixed> */
    private function reportingLine(User $user, OrgChart $chart): array
    {
        $today = $chart->day();
        $unit = $chart->primaryEmployment($user)?->getPosition()->getUnit();
        $path = [];
        for ($node = $unit, $guard = 0; $node !== null && $guard <= OrgUnit::MAX_DEPTH; $node = $node->getParent(), ++$guard) {
            array_unshift($path, ['id' => $node->getId(), 'name' => $node->getName(), 'kind' => $node->getKind()]);
        }
        $deputy = $chart->deputyFor($user);

        return [
            'user' => $this->views->person($user, $chart),
            'unit_path' => $path,
            'employments' => array_map(fn (Employment $employment): array => $this->views->employment($employment, $today), $chart->employmentsOf($user)),
            'superiors' => $this->views->people($chart->superiors($user), $chart),
            'direct_subordinates' => $this->views->people($chart->subordinates($user, true), $chart),
            'subordinate_count' => count($chart->subordinates($user)),
            'managed_unit_ids' => $chart->managedUnitIds($user),
            'deputy' => $deputy !== null ? $this->views->person($deputy, $chart) : null,
            'acting_for' => $this->views->people($chart->principalsOf($user), $chart),
        ];
    }

    /** @param callable(OrgUnit): void $change */
    private function changeUnit(int $id, callable $change, string $event): JsonResponse
    {
        $unit = $this->unitRepository->find($id);
        if (!$unit instanceof OrgUnit) {
            return $this->error('Unit not found.', Response::HTTP_NOT_FOUND);
        }
        try {
            $change($unit);
        } catch (\DomainException $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_CONFLICT);
        }
        $this->entityManager->flush();
        $this->auditLogger->info($event, ['unit_id' => $id, 'actor' => $this->actorName()]);

        return $this->json($this->views->unit($unit, $this->subordination->chartOn()));
    }

    private function codeTaken(string $code, ?OrgUnit $except): bool
    {
        $existing = $this->unitRepository->findOneBy(['code' => strtoupper(trim($code))]);

        return $existing !== null && $existing !== $except;
    }

    private function date(string $value): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false) {
            throw new \InvalidArgumentException('Dates must use YYYY-MM-DD.');
        }

        return $date;
    }

    /** @return int|null|false the positive integer, null when absent, false when malformed */
    private function positiveQueryInt(Request $request, string $key): int|null|false
    {
        $value = $request->query->get($key);
        if ($value === null || $value === '') {
            return null;
        }

        return ctype_digit((string) $value) && (int) $value > 0 ? (int) $value : false;
    }

    private function error(string $message, int $status): JsonResponse
    {
        return $this->json(['error' => $message], $status);
    }

    private function actorName(): string
    {
        $user = $this->getUser();

        return $user instanceof User ? $user->getUserIdentifier() : 'anonymous';
    }
}
