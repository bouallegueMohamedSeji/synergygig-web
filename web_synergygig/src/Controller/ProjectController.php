<?php

namespace App\Controller;

use App\Entity\Project;
use App\Entity\Task;
use App\Entity\User;
use App\Form\ProjectType;
use App\Repository\ProjectRepository;
use App\Repository\TaskRepository;
use App\Service\AIService;
use App\Service\CalendarSyncService;
use App\Service\GitHubService;
use App\Service\ProjectRiskService;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[Route('/projects')]
class ProjectController extends AbstractController
{
    #[Route('/', name: 'app_project_index')]
    public function index(Request $request, ProjectRepository $repo, TaskRepository $taskRepo, PaginatorInterface $paginator): Response
    {
        $user = $this->getUser();

        if ($this->isGranted('ROLE_HR')) {
            $qb = $repo->createQueryBuilder('p')->orderBy('p.id', 'DESC');
        } elseif ($this->isGranted('ROLE_PROJECT_OWNER')) {
            $qb = $repo->createQueryBuilder('p')
                ->where('p.owner = :user')->setParameter('user', $user)
                ->orderBy('p.id', 'DESC');
        } else {
            $myTasks = $taskRepo->findBy(['assignedTo' => $user]);
            /** @var \App\Entity\Task[] $myTasks */
            $projectIds = array_unique(array_filter(array_map(
                fn($t) => $t->getProject()?->getId(), $myTasks
            )));
            if ($projectIds) {
                $qb = $repo->createQueryBuilder('p')
                    ->where('p.id IN (:ids)')->setParameter('ids', $projectIds)
                    ->orderBy('p.id', 'DESC');
            } else {
                $qb = $repo->createQueryBuilder('p')->where('1=0');
            }
        }

        $pagination = $paginator->paginate(
            $qb,
            $request->query->getInt('page', 1),
            12
        );

        return $this->render('project/index.html.twig', [
            'pagination' => $pagination,
        ]);
    }

    #[Route('/new', name: 'app_project_new')]
    #[IsGranted('ROLE_PROJECT_OWNER')]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $project = new Project();
        $form = $this->createForm(ProjectType::class, $project);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($project);
            $em->flush();
            $this->addFlash('success', 'Project created.');
            return $this->redirectToRoute('app_project_index');
        }

        return $this->render('project/form.html.twig', [
            'form' => $form->createView(),
            'is_edit' => false,
        ]);
    }

    #[Route('/{id}/kanban', name: 'app_project_kanban', requirements: ['id' => '\d+'])]
    public function kanban(Project $project, TaskRepository $taskRepo): Response
    {
        $tasks = $taskRepo->findBy(['project' => $project]);
        /** @var \App\Entity\Task[] $tasks */
        $columns = [
            'TODO' => [],
            'IN_PROGRESS' => [],
            'IN_REVIEW' => [],
            'DONE' => [],
        ];
        foreach ($tasks as $task) {
            $status = strtoupper($task->getStatus() ?? 'TODO');
            if (!isset($columns[$status])) {
                $columns['TODO'][] = $task;
            } else {
                $columns[$status][] = $task;
            }
        }

        return $this->render('project/kanban.html.twig', [
            'project' => $project,
            'columns' => $columns,
        ]);
    }

    #[Route('/{id}', name: 'app_project_show', requirements: ['id' => '\d+'])]
    public function show(Project $project, TaskRepository $taskRepo): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User || !$this->canViewProject($project, $user)) {
            throw new AccessDeniedException('Access denied.');
        }

        $tasks = $taskRepo->findBy(['project' => $project], ['id' => 'DESC']);
        return $this->render('project/show.html.twig', [
            'project' => $project,
            'tasks' => $tasks,
        ]);
    }

    #[Route('/{id}/intelligence/risk', name: 'app_project_risk_forecast', methods: ['GET'], requirements: ['id' => '\\d+'])]
    public function riskForecast(
        Project $project,
        Request $request,
        ProjectRiskService $projectRiskService,
        RateLimiterFactory $integrationApiLimiter
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['message' => 'Not authenticated.'], 401);
        }
        if (!$this->canViewProject($project, $user)) {
            return $this->json(['message' => 'Access denied.'], 403);
        }

        $limiter = $integrationApiLimiter->create(($request->getClientIp() ?? 'unknown') . ':project-web-risk');
        if (!$limiter->consume(1)->isAccepted()) {
            return $this->json(['message' => 'Too many requests.'], 429);
        }

        $lat = (float) $request->query->get('lat', '36.8');
        $lon = (float) $request->query->get('lon', '10.18');
        $country = strtoupper((string) $request->query->get('country', 'TN'));
        $days = (int) $request->query->get('days', 30);

        if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
            return $this->json(['message' => 'Invalid coordinates.'], 400);
        }
        if (!preg_match('/^[A-Z]{2}$/', $country)) {
            return $this->json(['message' => 'Invalid country code.'], 400);
        }
        if ($days < 1 || $days > 90) {
            return $this->json(['message' => 'Invalid days window. Must be between 1 and 90.'], 400);
        }

        $riskData = $projectRiskService->buildForecast($project, $lat, $lon, $country, $days);
        if (!$riskData) {
            return $this->json(['message' => 'Risk forecast providers unavailable.'], 502);
        }

        return $this->json($riskData);
    }

    #[Route('/{id}/intelligence/calendar-sync/{milestoneId}', name: 'app_project_calendar_preview', methods: ['POST'], requirements: ['id' => '\\d+', 'milestoneId' => '\\d+'])]
    public function calendarPreview(
        Project $project,
        int $milestoneId,
        Request $request,
        EntityManagerInterface $em,
        CalendarSyncService $calendarSyncService,
        RateLimiterFactory $integrationApiLimiter
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['message' => 'Not authenticated.'], 401);
        }
        if (!$this->canManageProject($project, $user)) {
            return $this->json(['message' => 'Access denied.'], 403);
        }

        $limiter = $integrationApiLimiter->create(($request->getClientIp() ?? 'unknown') . ':project-web-calendar');
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

        $result = $calendarSyncService->createMilestoneSyncPayload($project, $milestoneId, $payload, $task);
        return $this->json($result, 202);
    }

    #[Route('/{id}/edit', name: 'app_project_edit', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_PROJECT_OWNER')]
    public function edit(Request $request, Project $project, EntityManagerInterface $em): Response
    {
        if (!$this->isGranted('ROLE_ADMIN') && $project->getOwner() !== $this->getUser()) {
            throw $this->createAccessDeniedException('You can only edit your own projects.');
        }
        $form = $this->createForm(ProjectType::class, $project);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Project updated.');
            return $this->redirectToRoute('app_project_index');
        }

        return $this->render('project/form.html.twig', [
            'form' => $form->createView(),
            'is_edit' => true,
            'project' => $project,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_project_delete', methods: ['POST'])]
    #[IsGranted('ROLE_PROJECT_OWNER')]
    public function delete(Request $request, Project $project, EntityManagerInterface $em): Response
    {
        if (!$this->isGranted('ROLE_ADMIN') && $project->getOwner() !== $this->getUser()) {
            throw $this->createAccessDeniedException('You can only delete your own projects.');
        }
        if ($this->isCsrfTokenValid('delete' . $project->getId(), (string) $request->request->get('_token'))) {
            $em->remove($project);
            $em->flush();
            $this->addFlash('success', 'Project deleted.');
        }
        return $this->redirectToRoute('app_project_index');
    }

    #[Route('/task/{id}/move', name: 'app_task_move', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function moveTask(Request $request, Task $task, EntityManagerInterface $em): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $newStatus = strtoupper($data['status'] ?? '');
        $token = $data['_token'] ?? '';

        if (!$this->isCsrfTokenValid('task_move', $token)) {
            return new JsonResponse(['error' => 'Invalid CSRF token'], 403);
        }

        // Only project owner, task assignee, or admin can move tasks
        $user = $this->getUser();
        $isOwner = $task->getProject()?->getOwner()?->getId() === $user?->getId();
        $isAssignee = $task->getAssignedTo()?->getId() === $user?->getId();
        if (!$this->isGranted('ROLE_ADMIN') && !$isOwner && !$isAssignee) {
            return new JsonResponse(['error' => 'Access denied'], 403);
        }

        $valid = ['TODO', 'IN_PROGRESS', 'IN_REVIEW', 'DONE'];
        if (!in_array($newStatus, $valid, true)) {
            return new JsonResponse(['error' => 'Invalid status'], 400);
        }

        $task->setStatus($newStatus);
        $em->flush();

        return new JsonResponse(['success' => true, 'status' => $newStatus]);
    }

    // ─────────────────────────────────────────────────────────────────
    //  PROJECT AI ENDPOINTS
    // ─────────────────────────────────────────────────────────────────

    /**
     * POST /projects/{id}/ai/generate-tasks
     * Generates tasks from the project description and persists them.
     */
    #[Route('/{id}/ai/generate-tasks', name: 'app_project_ai_generate_tasks', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function aiGenerateTasks(
        Project $project,
        Request $request,
        EntityManagerInterface $em,
        TaskRepository $taskRepo,
        AIService $aiService,
        RateLimiterFactory $integrationApiLimiter
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }
        if (!$this->canManageProject($project, $user)) {
            return $this->json(['error' => 'Access denied.'], 403);
        }

        $limiter = $integrationApiLimiter->create(($request->getClientIp() ?? 'unknown') . ':project-ai-tasks');
        if (!$limiter->consume(1)->isAccepted()) {
            return $this->json(['error' => 'Too many requests. Please wait before generating again.'], 429);
        }

        // Count team size (distinct assignees on this project + 1)
        /** @var \App\Entity\Task[] $tasksForTeam */
        $tasksForTeam = $taskRepo->findBy(['project' => $project]);
        $teamSize = max(1, count(array_unique(array_filter(
            array_map(fn($t) => $t->getAssignedTo()?->getId(), $tasksForTeam)
        ))));

        $generated = $aiService->generateProjectTasks(
            (string) $project->getName(),
            $project->getDescription() ?? '',
            $teamSize
        );

        if (empty($generated)) {
            return $this->json(['error' => 'AI returned no tasks. Please try again.'], 502);
        }

        $created = [];
        foreach ($generated as $taskData) {
            $title = is_string($taskData['title'] ?? null) ? substr(trim($taskData['title']), 0, 255) : null;
            if (!$title) {
                continue;
            }
            $priority = strtoupper((string) ($taskData['priority'] ?? 'MEDIUM'));
            if (!in_array($priority, ['HIGH', 'MEDIUM', 'LOW'], true)) {
                $priority = 'MEDIUM';
            }

            $task = new Task();
            $task->setProject($project);
            $task->setTitle($title);
            $task->setDescription(is_string($taskData['description'] ?? null) ? $taskData['description'] : '');
            $task->setStatus('TODO');
            $task->setPriority($priority);
            $em->persist($task);

            $created[] = [
                'title'       => $title,
                'description' => $task->getDescription(),
                'priority'    => $priority,
                'status'      => 'TODO',
            ];
        }

        $em->flush();

        return $this->json([
            'success' => true,
            'count'   => count($created),
            'tasks'   => $created,
        ]);
    }

    /**
     * POST /projects/{id}/ai/sprint-plan
     * Returns sprint plan JSON from current task list.
     */
    #[Route('/{id}/ai/sprint-plan', name: 'app_project_ai_sprint_plan', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function aiSprintPlan(
        Project $project,
        Request $request,
        TaskRepository $taskRepo,
        AIService $aiService,
        RateLimiterFactory $integrationApiLimiter
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }
        if (!$this->canManageProject($project, $user)) {
            return $this->json(['error' => 'Access denied.'], 403);
        }

        $limiter = $integrationApiLimiter->create(($request->getClientIp() ?? 'unknown') . ':project-ai-sprint');
        if (!$limiter->consume(1)->isAccepted()) {
            return $this->json(['error' => 'Too many requests.'], 429);
        }

        $payload    = json_decode($request->getContent(), true) ?? [];
        $sprintDays = max(1, min(90, (int) ($payload['sprint_days'] ?? 10)));

        $tasks = $taskRepo->findBy(['project' => $project]);
        /** @var \App\Entity\Task[] $tasks */
        if (empty($tasks)) {
            return $this->json(['error' => 'No tasks found. Add tasks before planning a sprint.'], 400);
        }

        // Build task JSON for AI
        $taskArr = array_map(fn($t) => [
            'id'       => $t->getId(),
            'title'    => $t->getTitle(),
            'status'   => $t->getStatus() ?? 'TODO',
            'priority' => $t->getPriority() ?? 'MEDIUM',
        ], $tasks);

        $teamSize = max(1, count(array_unique(array_filter(
            array_map(fn($t) => $t->getAssignedTo()?->getId(), $tasks)
        ))));

        $tasksEncoded = json_encode($taskArr);
        $result = $aiService->planSprint($tasksEncoded !== false ? $tasksEncoded : '[]', $teamSize, $sprintDays);
        if (!$result) {
            return $this->json(['error' => 'Sprint planning failed. Please try again.'], 502);
        }

        $parsed = json_decode($result, true);
        if (!is_array($parsed)) {
            return $this->json(['raw' => $result]);
        }

        return $this->json($parsed);
    }

    /**
     * POST /projects/{id}/ai/meeting
     * Two modes: "prepare" (agenda from project state) or "summarize" (free-text notes).
     */
    #[Route('/{id}/ai/meeting', name: 'app_project_ai_meeting', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function aiMeeting(
        Project $project,
        Request $request,
        TaskRepository $taskRepo,
        AIService $aiService,
        RateLimiterFactory $integrationApiLimiter
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }
        if (!$this->canManageProject($project, $user)) {
            return $this->json(['error' => 'Access denied.'], 403);
        }

        $limiter = $integrationApiLimiter->create(($request->getClientIp() ?? 'unknown') . ':project-ai-meeting');
        if (!$limiter->consume(1)->isAccepted()) {
            return $this->json(['error' => 'Too many requests.'], 429);
        }

        $payload = json_decode($request->getContent(), true) ?? [];
        $mode    = $payload['mode'] ?? 'prepare';

        if ($mode === 'summarize') {
            $notes = trim((string) ($payload['notes'] ?? ''));
            if (strlen($notes) < 10) {
                return $this->json(['error' => 'Please provide meeting notes to summarize.'], 400);
            }
            $summary = $aiService->summarizeMeeting($notes);
            return $this->json(['result' => $summary]);
        }

        // "prepare" mode: build context from project tasks
        $tasks = $taskRepo->findBy(['project' => $project]);
        /** @var \App\Entity\Task[] $tasks */
        $tasksText = implode("\n", array_map(
            fn($t) => sprintf('- [%s] %s (%s)', $t->getStatus() ?? 'TODO', $t->getTitle(), $t->getPriority() ?? 'MEDIUM'),
            $tasks
        ));
        if (!$tasksText) {
            $tasksText = '- No tasks yet.';
        }

        $agenda = $aiService->prepMeeting((string) $project->getName(), $tasksText, 'Team (see project members)');
        return $this->json(['result' => $agenda]);
    }

    /**
     * POST /projects/{id}/ai/decision
     * AI-powered decision analysis using weighted criteria scoring.
     */
    #[Route('/{id}/ai/decision', name: 'app_project_ai_decision', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function aiDecision(
        Project $project,
        Request $request,
        AIService $aiService,
        RateLimiterFactory $integrationApiLimiter
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }
        if (!$this->canManageProject($project, $user)) {
            return $this->json(['error' => 'Access denied.'], 403);
        }

        $limiter = $integrationApiLimiter->create(($request->getClientIp() ?? 'unknown') . ':project-ai-decision');
        if (!$limiter->consume(1)->isAccepted()) {
            return $this->json(['error' => 'Too many requests.'], 429);
        }

        $payload  = json_decode($request->getContent(), true) ?? [];
        $question = trim((string) ($payload['question'] ?? ''));
        $options  = trim((string) ($payload['options'] ?? ''));
        $criteria = trim((string) ($payload['criteria'] ?? ''));

        if (strlen($question) < 5) {
            return $this->json(['error' => 'Please provide a decision question.'], 400);
        }

        $result = $aiService->helpDecide(
            $question,
            $options ?: 'Not specified',
            $criteria ?: 'Not specified'
        );

        return $this->json(['result' => $result]);
    }

    private function canViewProject(Project $project, User $user): bool
    {
        if ($this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_HR')) {
            return true;
        }

        if ($this->isGranted('ROLE_PROJECT_OWNER') && $project->getOwner()?->getId() === $user->getId()) {
            return true;
        }

        foreach ($project->getTasks() as $task) {
            if ($task->getAssignedTo()?->getId() === $user->getId()) {
                return true;
            }
        }

        return false;
    }

    private function canManageProject(Project $project, User $user): bool
    {
        return $this->isGranted('ROLE_ADMIN')
            || $this->isGranted('ROLE_HR')
            || $project->getOwner()?->getId() === $user->getId();
    }

    // ── GitHub Issues Integration ─────────────────────────────────────────────

    /**
     * POST /projects/{id}/github/set-repo
     * Body JSON: { "repo": "owner/repo" }
     * Links a GitHub repo to this project (stored in projects.github_repo).
     */
    #[Route('/{id}/github/set-repo', name: 'app_project_github_set_repo', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function githubSetRepo(
        Project $project,
        Request $request,
        EntityManagerInterface $em
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof User || !$this->canManageProject($project, $user)) {
            return $this->json(['error' => 'Access denied.'], 403);
        }
        $data = json_decode($request->getContent(), true) ?? [];
        $repo = trim($data['repo'] ?? '');
        if (!$repo || !preg_match('#^[\w.\-]+/[\w.\-]+$#', $repo)) {
            return $this->json(['error' => 'Invalid repo format. Use "owner/repo".'], 400);
        }
        $project->setGithubRepo($repo);
        $em->flush();
        return $this->json(['success' => true, 'repo' => $repo]);
    }

    /**
     * GET /projects/{id}/github/issues
     * Returns open GitHub issues for the linked repo.
     */
    #[Route('/{id}/github/issues', name: 'app_project_github_issues', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function githubListIssues(
        Project $project,
        GitHubService $gitHub
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof User || !$this->canViewProject($project, $user)) {
            return $this->json(['error' => 'Access denied.'], 403);
        }
        $repo = $project->getGithubRepo();
        if (!$repo) {
            return $this->json(['error' => 'No GitHub repo linked to this project.'], 400);
        }
        $issues = $gitHub->listIssues($repo);
        return $this->json(['issues' => $issues]);
    }

    /**
     * POST /projects/{id}/tasks/{taskId}/github/create-issue
     * Creates a GitHub issue from a task and stores the issue number+URL on the task.
     */
    #[Route('/{id}/tasks/{taskId}/github/create-issue', name: 'app_project_github_create_issue', methods: ['POST'], requirements: ['id' => '\d+', 'taskId' => '\d+'])]
    public function githubCreateIssue(
        Project $project,
        int $taskId,
        Request $request,
        TaskRepository $taskRepo,
        EntityManagerInterface $em,
        GitHubService $gitHub
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof User || !$this->canManageProject($project, $user)) {
            return $this->json(['error' => 'Access denied.'], 403);
        }
        $repo = $project->getGithubRepo();
        if (!$repo) {
            return $this->json(['error' => 'No GitHub repo linked to this project. Link one first.'], 400);
        }
        /** @var Task|null $task */
        $task = $taskRepo->find($taskId);
        if (!$task || $task->getProject()?->getId() !== $project->getId()) {
            return $this->json(['error' => 'Task not found in this project.'], 404);
        }
        if ($task->getGithubIssueNumber()) {
            return $this->json([
                'error'      => 'Issue already exists.',
                'issue_url'  => $task->getGithubIssueUrl(),
                'issue_number' => $task->getGithubIssueNumber(),
            ], 400);
        }
        $body = $task->getDescription() ?? '';
        $body .= "\n\n---\n*Synced from SynergyGig task #" . $task->getId() . "*";
        $labels = ['synergygig'];
        if ($task->getPriority()) {
            $labels[] = 'priority:' . strtolower($task->getPriority());
        }
        $result = $gitHub->createIssue($repo, (string) $task->getTitle(), $body, $labels);
        if (!$result) {
            return $this->json(['error' => 'Failed to create GitHub issue. Check GITHUB_TOKEN.'], 500);
        }
        $task->setGithubIssueNumber($result['number']);
        $task->setGithubIssueUrl($result['html_url']);
        $em->flush();
        return $this->json([
            'success'      => true,
            'issue_number' => $result['number'],
            'issue_url'    => $result['html_url'],
        ]);
    }

    /**
     * POST /projects/{id}/tasks/{taskId}/github/close-issue
     * Closes the linked GitHub issue for a task.
     */
    #[Route('/{id}/tasks/{taskId}/github/close-issue', name: 'app_project_github_close_issue', methods: ['POST'], requirements: ['id' => '\d+', 'taskId' => '\d+'])]
    public function githubCloseIssue(
        Project $project,
        int $taskId,
        TaskRepository $taskRepo,
        GitHubService $gitHub
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof User || !$this->canManageProject($project, $user)) {
            return $this->json(['error' => 'Access denied.'], 403);
        }
        $repo = $project->getGithubRepo();
        if (!$repo) {
            return $this->json(['error' => 'No GitHub repo linked.'], 400);
        }
        /** @var Task|null $task */
        $task = $taskRepo->find($taskId);
        if (!$task || $task->getProject()?->getId() !== $project->getId()) {
            return $this->json(['error' => 'Task not found.'], 404);
        }
        $issueNumber = $task->getGithubIssueNumber();
        if (!$issueNumber) {
            return $this->json(['error' => 'Task has no linked GitHub issue.'], 400);
        }
        $ok = $gitHub->closeIssue($repo, $issueNumber);
        return $this->json(['success' => $ok]);
    }
}
