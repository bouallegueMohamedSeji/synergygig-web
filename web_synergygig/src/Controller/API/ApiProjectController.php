<?php

namespace App\Controller\API;

use App\Entity\Project;
use App\Entity\Task;
use App\Entity\User;
use App\Service\CalendarSyncService;
use App\Service\ProjectRiskService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/projects')]
class ApiProjectController extends AbstractController
{
    public function __construct(
        private ProjectRiskService $projectRiskService,
        private CalendarSyncService $calendarSyncService,
        private RateLimiterFactory $integrationApiLimiter,
    ) {
    }

    #[Route('', name: 'api_projects_index', methods: ['GET'])]
    public function index(EntityManagerInterface $em, #[CurrentUser] ?User $currentUser): JsonResponse
    {
        if (!$currentUser) {
            return $this->json(['message' => 'Not authenticated.'], 401);
        }

        $repo = $em->getRepository(Project::class);
        $taskRepo = $em->getRepository(Task::class);

        if ($this->isGranted('ROLE_HR') || $this->isGranted('ROLE_ADMIN')) {
            $projects = $repo->findBy([], ['id' => 'DESC'], 100);
        } elseif ($this->isGranted('ROLE_PROJECT_OWNER')) {
            $projects = $repo->findBy(['owner' => $currentUser], ['id' => 'DESC']);
        } else {
            $myTasks = $taskRepo->findBy(['assignedTo' => $currentUser]);
            $projectIds = array_values(array_unique(array_filter(array_map(
                static fn(Task $task) => $task->getProject()?->getId(),
                $myTasks
            ))));
            if (empty($projectIds)) {
                $projects = [];
            } else {
                $projects = $repo->createQueryBuilder('p')
                    ->where('p.id IN (:ids)')
                    ->setParameter('ids', $projectIds)
                    ->orderBy('p.id', 'DESC')
                    ->getQuery()
                    ->getResult();
            }
        }

        return $this->json(array_map([$this, 'serializeProject'], $projects));
    }

    #[Route('/owner/{ownerId}', name: 'api_projects_by_owner', methods: ['GET'], requirements: ['ownerId' => '\\d+'])]
    public function byOwner(int $ownerId, EntityManagerInterface $em, #[CurrentUser] ?User $currentUser): JsonResponse
    {
        if (!$currentUser) {
            return $this->json(['message' => 'Not authenticated.'], 401);
        }

        if (!$this->isGranted('ROLE_ADMIN') && !$this->isGranted('ROLE_HR') && $currentUser->getId() !== $ownerId) {
            return $this->json(['message' => 'Access denied.'], 403);
        }

        $owner = $em->getRepository(User::class)->find($ownerId);
        if (!$owner) {
            return $this->json([]);
        }

        $projects = $em->getRepository(Project::class)->findBy(['owner' => $owner], ['id' => 'DESC']);
        return $this->json(array_map([$this, 'serializeProject'], $projects));
    }

    #[Route('/{id}', name: 'api_projects_show', methods: ['GET'], requirements: ['id' => '\\d+'])]
    public function show(Project $project, #[CurrentUser] ?User $currentUser): JsonResponse
    {
        if (!$currentUser) {
            return $this->json(['message' => 'Not authenticated.'], 401);
        }
        if (!$this->canViewProject($project, $currentUser)) {
            return $this->json(['message' => 'Access denied.'], 403);
        }
        return $this->json($this->serializeProject($project));
    }

    #[Route('/{id}/risk/forecast', name: 'api_projects_risk_forecast', methods: ['GET'], requirements: ['id' => '\\d+'])]
    public function riskForecast(Project $project, Request $request, #[CurrentUser] ?User $currentUser): JsonResponse
    {
        if (!$currentUser) {
            return $this->json(['message' => 'Not authenticated.'], 401);
        }
        if (!$this->canViewProject($project, $currentUser)) {
            return $this->json(['message' => 'Access denied.'], 403);
        }

        $limiter = $this->integrationApiLimiter->create(($request->getClientIp() ?? 'unknown') . ':project-risk');
        if (!$limiter->consume(1)->isAccepted()) {
            return $this->json(['message' => 'Too many requests.'], 429);
        }

        $days = (int) $request->query->get('days', 30);

        if ($days < 1 || $days > 90) {
            return $this->json(['message' => 'Invalid days window. Must be between 1 and 90.'], 400);
        }

        $riskData = $this->projectRiskService->buildForecast($project, $days);

        return $this->json($riskData);
    }

    #[Route('/{id}/milestones/{milestoneId}/calendar-sync', name: 'api_projects_calendar_sync', methods: ['POST'], requirements: ['id' => '\\d+', 'milestoneId' => '\\d+'])]
    public function calendarSync(
        Project $project,
        int $milestoneId,
        Request $request,
        EntityManagerInterface $em,
        #[CurrentUser] ?User $currentUser
    ): JsonResponse {
        if (!$currentUser) {
            return $this->json(['message' => 'Not authenticated.'], 401);
        }

        $isOwner = $project->getOwner()?->getId() === $currentUser->getId();
        if (!$this->isGranted('ROLE_ADMIN') && !$this->isGranted('ROLE_HR') && !$isOwner) {
            return $this->json(['message' => 'Access denied.'], 403);
        }

        $limiter = $this->integrationApiLimiter->create(($request->getClientIp() ?? 'unknown') . ':project-calendar');
        if (!$limiter->consume(1)->isAccepted()) {
            return $this->json(['message' => 'Too many requests.'], 429);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            $payload = [];
        }

        $task = $em->getRepository(Task::class)->find($milestoneId);
        if ($task && $task->getProject()?->getId() !== $project->getId()) {
            return $this->json(['message' => 'Milestone task does not belong to this project.'], 400);
        }

        $result = $this->calendarSyncService->createMilestoneSyncPayload($project, $milestoneId, $payload, $task);
        return $this->json($result, 202);
    }

    #[Route('', name: 'api_projects_create', methods: ['POST'])]
    public function create(Request $request, EntityManagerInterface $em, #[CurrentUser] ?User $currentUser): JsonResponse
    {
        if (!$currentUser) {
            return $this->json(['message' => 'Not authenticated.'], 401);
        }
        if (!$this->isGranted('ROLE_PROJECT_OWNER') && !$this->isGranted('ROLE_ADMIN') && !$this->isGranted('ROLE_HR')) {
            return $this->json(['message' => 'Access denied.'], 403);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return $this->json(['message' => 'name is required.'], 400);
        }

        $owner = $currentUser;
        if (isset($data['owner_id'])) {
            $requestedOwner = $em->getRepository(User::class)->find((int) $data['owner_id']);
            if ($requestedOwner) {
                $owner = $requestedOwner;
            }
        }

        $project = new Project();
        $project->setName($name);
        $project->setDescription(isset($data['description']) ? (string) $data['description'] : null);
        $project->setOwner($owner);
        $project->setStatus($this->normalizeProjectStatus((string) ($data['status'] ?? 'PLANNING')));
        $project->initCreatedAt();

        if (!empty($data['start_date'])) {
            try {
                $project->setStartDate(new \DateTimeImmutable((string) $data['start_date']));
            } catch (\Throwable) {
                return $this->json(['message' => 'Invalid start_date. Use YYYY-MM-DD.'], 400);
            }
        }

        if (!empty($data['deadline'])) {
            try {
                $project->setDeadline(new \DateTimeImmutable((string) $data['deadline']));
            } catch (\Throwable) {
                return $this->json(['message' => 'Invalid deadline. Use YYYY-MM-DD.'], 400);
            }
        }

        if (!empty($data['department_id'])) {
            $department = $em->getRepository(\App\Entity\Department::class)->find((int) $data['department_id']);
            if ($department) {
                $project->setDepartment($department);
            }
        }

        $em->persist($project);
        $em->flush();

        return $this->json($this->serializeProject($project), 201);
    }

    #[Route('/{id}', name: 'api_projects_update', methods: ['PUT'], requirements: ['id' => '\\d+'])]
    public function update(Project $project, Request $request, EntityManagerInterface $em, #[CurrentUser] ?User $currentUser): JsonResponse
    {
        if (!$currentUser) {
            return $this->json(['message' => 'Not authenticated.'], 401);
        }

        $isOwner = $project->getOwner()?->getId() === $currentUser->getId();
        if (!$this->isGranted('ROLE_ADMIN') && !$this->isGranted('ROLE_HR') && !$isOwner) {
            return $this->json(['message' => 'Access denied.'], 403);
        }

        $data = json_decode($request->getContent(), true) ?? [];

        if (array_key_exists('name', $data)) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                return $this->json(['message' => 'name cannot be empty.'], 400);
            }
            $project->setName($name);
        }
        if (array_key_exists('description', $data)) {
            $project->setDescription($data['description'] !== null ? (string) $data['description'] : null);
        }
        if (array_key_exists('status', $data)) {
            $project->setStatus($this->normalizeProjectStatus((string) $data['status']));
        }
        if (array_key_exists('start_date', $data)) {
            if ($data['start_date'] === null || $data['start_date'] === '') {
                $project->setStartDate(null);
            } else {
                try {
                    $project->setStartDate(new \DateTimeImmutable((string) $data['start_date']));
                } catch (\Throwable) {
                    return $this->json(['message' => 'Invalid start_date. Use YYYY-MM-DD.'], 400);
                }
            }
        }
        if (array_key_exists('deadline', $data)) {
            if ($data['deadline'] === null || $data['deadline'] === '') {
                $project->setDeadline(null);
            } else {
                try {
                    $project->setDeadline(new \DateTimeImmutable((string) $data['deadline']));
                } catch (\Throwable) {
                    return $this->json(['message' => 'Invalid deadline. Use YYYY-MM-DD.'], 400);
                }
            }
        }
        if (array_key_exists('owner_id', $data)) {
            $owner = $data['owner_id'] ? $em->getRepository(User::class)->find((int) $data['owner_id']) : null;
            if ($owner) {
                $project->setOwner($owner);
            }
        }
        if (array_key_exists('department_id', $data)) {
            if (!$data['department_id']) {
                $project->setDepartment(null);
            } else {
                $department = $em->getRepository(\App\Entity\Department::class)->find((int) $data['department_id']);
                $project->setDepartment($department);
            }
        }

        $em->flush();

        return $this->json($this->serializeProject($project));
    }

    #[Route('/{id}', name: 'api_projects_delete', methods: ['DELETE'], requirements: ['id' => '\\d+'])]
    public function delete(Project $project, EntityManagerInterface $em, #[CurrentUser] ?User $currentUser): JsonResponse
    {
        if (!$currentUser) {
            return $this->json(['message' => 'Not authenticated.'], 401);
        }

        $isOwner = $project->getOwner()?->getId() === $currentUser->getId();
        if (!$this->isGranted('ROLE_ADMIN') && !$this->isGranted('ROLE_HR') && !$isOwner) {
            return $this->json(['message' => 'Access denied.'], 403);
        }

        $em->remove($project);
        $em->flush();

        return $this->json(['success' => true]);
    }

    private function canViewProject(Project $project, User $currentUser): bool
    {
        if ($this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_HR')) {
            return true;
        }

        if ($this->isGranted('ROLE_PROJECT_OWNER') && $project->getOwner()?->getId() === $currentUser->getId()) {
            return true;
        }

        foreach ($project->getTasks() as $task) {
            if ($task->getAssignedTo()?->getId() === $currentUser->getId()) {
                return true;
            }
        }

        return false;
    }

    private function normalizeProjectStatus(string $status): string
    {
        $normalized = strtoupper(trim($status));
        $valid = ['PLANNING', 'IN_PROGRESS', 'COMPLETED'];
        return in_array($normalized, $valid, true) ? $normalized : 'PLANNING';
    }

    /** @return array<string, mixed> */
    private function serializeProject(Project $project): array
    {
        return [
            'id' => $project->getId(),
            'name' => $project->getName(),
            'description' => $project->getDescription(),
            'owner_id' => $project->getOwner()?->getId(),
            'start_date' => $project->getStartDate()?->format('Y-m-d'),
            'deadline' => $project->getDeadline()?->format('Y-m-d'),
            'status' => $project->getStatus() ?? 'PLANNING',
            'created_at' => $project->getCreatedAt()?->format('Y-m-d H:i:s'),
            'department_id' => $project->getDepartment()?->getId(),
        ];
    }
}
