<?php

namespace App\Controller\API;

use App\Entity\Project;
use App\Entity\Task;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/tasks')]
class ApiTaskController extends AbstractController
{
    #[Route('', name: 'api_tasks_index', methods: ['GET'])]
    public function index(EntityManagerInterface $em, #[CurrentUser] ?User $currentUser): JsonResponse
    {
        if (!$currentUser) {
            return $this->json(['message' => 'Not authenticated.'], 401);
        }

        $repo = $em->getRepository(Task::class);
        if ($this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_HR')) {
            $tasks = $repo->findBy([], ['id' => 'DESC'], 100);
        } elseif ($this->isGranted('ROLE_PROJECT_OWNER')) {
            $tasks = $repo->createQueryBuilder('t')
                ->leftJoin('t.project', 'p')
                ->where('p.owner = :owner')
                ->setParameter('owner', $currentUser)
                ->orderBy('t.id', 'DESC')
                ->getQuery()
                ->getResult();
        } else {
            $tasks = $repo->findBy(['assignedTo' => $currentUser], ['id' => 'DESC']);
        }

        return $this->json(array_map([$this, 'serializeTask'], $tasks));
    }

    #[Route('/project/{projectId}', name: 'api_tasks_by_project', methods: ['GET'], requirements: ['projectId' => '\\d+'])]
    public function byProject(int $projectId, EntityManagerInterface $em, #[CurrentUser] ?User $currentUser): JsonResponse
    {
        if (!$currentUser) {
            return $this->json(['message' => 'Not authenticated.'], 401);
        }

        $project = $em->getRepository(Project::class)->find($projectId);
        if (!$project) {
            return $this->json([]);
        }
        if (!$this->canViewProject($project, $currentUser)) {
            return $this->json(['message' => 'Access denied.'], 403);
        }

        $tasks = $em->getRepository(Task::class)->findBy(['project' => $project], ['id' => 'DESC']);
        return $this->json(array_map([$this, 'serializeTask'], $tasks));
    }

    #[Route('/assignee/{userId}', name: 'api_tasks_by_assignee', methods: ['GET'], requirements: ['userId' => '\\d+'])]
    public function byAssignee(int $userId, EntityManagerInterface $em, #[CurrentUser] ?User $currentUser): JsonResponse
    {
        if (!$currentUser) {
            return $this->json(['message' => 'Not authenticated.'], 401);
        }

        if (!$this->isGranted('ROLE_ADMIN') && !$this->isGranted('ROLE_HR') && $currentUser->getId() !== $userId) {
            return $this->json(['message' => 'Access denied.'], 403);
        }

        $assignee = $em->getRepository(User::class)->find($userId);
        if (!$assignee) {
            return $this->json([]);
        }

        $tasks = $em->getRepository(Task::class)->findBy(['assignedTo' => $assignee], ['id' => 'DESC']);
        return $this->json(array_map([$this, 'serializeTask'], $tasks));
    }

    #[Route('/{id}', name: 'api_tasks_show', methods: ['GET'], requirements: ['id' => '\\d+'])]
    public function show(Task $task, #[CurrentUser] ?User $currentUser): JsonResponse
    {
        if (!$currentUser) {
            return $this->json(['message' => 'Not authenticated.'], 401);
        }
        if (!$this->canViewTask($task, $currentUser)) {
            return $this->json(['message' => 'Access denied.'], 403);
        }
        return $this->json($this->serializeTask($task));
    }

    #[Route('', name: 'api_tasks_create', methods: ['POST'])]
    public function create(Request $request, EntityManagerInterface $em, #[CurrentUser] ?User $currentUser): JsonResponse
    {
        if (!$currentUser) {
            return $this->json(['message' => 'Not authenticated.'], 401);
        }
        if (!$this->isGranted('ROLE_PROJECT_OWNER') && !$this->isGranted('ROLE_ADMIN') && !$this->isGranted('ROLE_HR')) {
            return $this->json(['message' => 'Access denied.'], 403);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $projectId = (int) ($data['project_id'] ?? 0);
        $title = trim((string) ($data['title'] ?? ''));

        if ($projectId <= 0 || $title === '') {
            return $this->json(['message' => 'project_id and title are required.'], 400);
        }

        $project = $em->getRepository(Project::class)->find($projectId);
        if (!$project) {
            return $this->json(['message' => 'Project not found.'], 404);
        }
        if (!$this->canManageProject($project, $currentUser)) {
            return $this->json(['message' => 'Access denied.'], 403);
        }

        $task = new Task();
        $task->setProject($project);
        $task->setTitle($title);
        $task->setDescription(isset($data['description']) ? (string) $data['description'] : null);
        $task->setStatus($this->normalizeTaskStatus((string) ($data['status'] ?? 'TODO')));
        $task->setPriority($this->normalizePriority((string) ($data['priority'] ?? 'MEDIUM')));
        $task->initCreatedAt();

        if (!empty($data['assigned_to'])) {
            $assignee = $em->getRepository(User::class)->find((int) $data['assigned_to']);
            if ($assignee) {
                $task->setAssignedTo($assignee);
            }
        }

        if (!empty($data['due_date'])) {
            try {
                $task->setDueDate(new \DateTimeImmutable((string) $data['due_date']));
            } catch (\Throwable) {
                return $this->json(['message' => 'Invalid due_date. Use YYYY-MM-DD.'], 400);
            }
        }

        if (array_key_exists('submission_text', $data)) {
            $task->setSubmissionText($data['submission_text'] !== null ? (string) $data['submission_text'] : null);
        }
        if (array_key_exists('submission_file', $data)) {
            $task->setSubmissionFile($data['submission_file'] !== null ? (string) $data['submission_file'] : null);
        }

        $em->persist($task);
        $em->flush();

        return $this->json($this->serializeTask($task), 201);
    }

    #[Route('/{id}', name: 'api_tasks_update', methods: ['PUT'], requirements: ['id' => '\\d+'])]
    public function update(Task $task, Request $request, EntityManagerInterface $em, #[CurrentUser] ?User $currentUser): JsonResponse
    {
        if (!$currentUser) {
            return $this->json(['message' => 'Not authenticated.'], 401);
        }
        if (!$this->canManageTask($task, $currentUser) && !$this->canSelfUpdateAssignedTask($task, $currentUser)) {
            return $this->json(['message' => 'Access denied.'], 403);
        }

        $data = json_decode($request->getContent(), true) ?? [];

        if (array_key_exists('project_id', $data)) {
            $project = $data['project_id'] ? $em->getRepository(Project::class)->find((int) $data['project_id']) : null;
            if ($project) {
                if (!$this->canManageProject($project, $currentUser)) {
                    return $this->json(['message' => 'Access denied for target project.'], 403);
                }
                $task->setProject($project);
            }
        }

        if (array_key_exists('assigned_to', $data)) {
            if (!$this->canManageTask($task, $currentUser)) {
                return $this->json(['message' => 'Only project owner/admin can reassign task.'], 403);
            }
            $assignee = $data['assigned_to'] ? $em->getRepository(User::class)->find((int) $data['assigned_to']) : null;
            $task->setAssignedTo($assignee);
        }

        if (array_key_exists('title', $data)) {
            $title = trim((string) $data['title']);
            if ($title === '') {
                return $this->json(['message' => 'title cannot be empty.'], 400);
            }
            $task->setTitle($title);
        }
        if (array_key_exists('description', $data)) {
            $task->setDescription($data['description'] !== null ? (string) $data['description'] : null);
        }
        if (array_key_exists('status', $data)) {
            $task->setStatus($this->normalizeTaskStatus((string) $data['status']));
        }
        if (array_key_exists('priority', $data)) {
            $task->setPriority($this->normalizePriority((string) $data['priority']));
        }
        if (array_key_exists('due_date', $data)) {
            if ($data['due_date'] === null || $data['due_date'] === '') {
                $task->setDueDate(null);
            } else {
                try {
                    $task->setDueDate(new \DateTimeImmutable((string) $data['due_date']));
                } catch (\Throwable) {
                    return $this->json(['message' => 'Invalid due_date. Use YYYY-MM-DD.'], 400);
                }
            }
        }
        if (array_key_exists('submission_text', $data)) {
            $task->setSubmissionText($data['submission_text'] !== null ? (string) $data['submission_text'] : null);
        }
        if (array_key_exists('submission_file', $data)) {
            $task->setSubmissionFile($data['submission_file'] !== null ? (string) $data['submission_file'] : null);
        }
        if (array_key_exists('review_status', $data)) {
            $task->setReviewStatus($data['review_status'] !== null ? strtoupper((string) $data['review_status']) : null);
        }
        if (array_key_exists('review_rating', $data)) {
            $task->setReviewRating($data['review_rating'] !== null ? (int) $data['review_rating'] : null);
        }
        if (array_key_exists('review_feedback', $data)) {
            $task->setReviewFeedback($data['review_feedback'] !== null ? (string) $data['review_feedback'] : null);
        }

        $em->flush();

        return $this->json($this->serializeTask($task));
    }

    #[Route('/{id}/review', name: 'api_tasks_review', methods: ['PUT'], requirements: ['id' => '\\d+'])]
    public function review(Task $task, Request $request, EntityManagerInterface $em, #[CurrentUser] ?User $currentUser): JsonResponse
    {
        if (!$currentUser) {
            return $this->json(['message' => 'Not authenticated.'], 401);
        }
        if (!$this->canManageTask($task, $currentUser)) {
            return $this->json(['message' => 'Only project owner/admin can review this task.'], 403);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $reviewStatus = strtoupper((string) ($data['review_status'] ?? ''));
        $reviewRating = (int) ($data['review_rating'] ?? 0);
        $reviewFeedback = trim((string) ($data['review_feedback'] ?? ''));

        if (!in_array($reviewStatus, ['APPROVED', 'NEEDS_REVISION', 'REJECTED'], true)) {
            return $this->json(['message' => 'Invalid review_status.'], 400);
        }
        if ($reviewRating < 1 || $reviewRating > 5) {
            return $this->json(['message' => 'review_rating must be between 1 and 5.'], 400);
        }
        if ($reviewFeedback === '') {
            return $this->json(['message' => 'review_feedback is required.'], 400);
        }

        $task->setReviewStatus($reviewStatus);
        $task->setReviewRating($reviewRating);
        $task->setReviewFeedback($reviewFeedback);
        $task->initReviewDate(new \DateTimeImmutable());

        if ($reviewStatus === 'APPROVED') {
            $task->setStatus('DONE');
        } elseif ($reviewStatus === 'NEEDS_REVISION') {
            $task->setStatus('IN_PROGRESS');
        }

        $em->flush();
        return $this->json($this->serializeTask($task));
    }

    #[Route('/{id}', name: 'api_tasks_delete', methods: ['DELETE'], requirements: ['id' => '\\d+'])]
    public function delete(Task $task, EntityManagerInterface $em, #[CurrentUser] ?User $currentUser): JsonResponse
    {
        if (!$currentUser) {
            return $this->json(['message' => 'Not authenticated.'], 401);
        }
        if (!$this->canManageTask($task, $currentUser)) {
            return $this->json(['message' => 'Access denied.'], 403);
        }

        $em->remove($task);
        $em->flush();
        return $this->json(['success' => true]);
    }

    private function canManageProject(Project $project, User $currentUser): bool
    {
        if ($this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_HR')) {
            return true;
        }
        return $project->getOwner()?->getId() === $currentUser->getId();
    }

    private function canViewProject(Project $project, User $currentUser): bool
    {
        if ($this->canManageProject($project, $currentUser)) {
            return true;
        }

        foreach ($project->getTasks() as $task) {
            if ($task->getAssignedTo()?->getId() === $currentUser->getId()) {
                return true;
            }
        }

        return false;
    }

    private function canManageTask(Task $task, User $currentUser): bool
    {
        if ($this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_HR')) {
            return true;
        }
        return $task->getProject()?->getOwner()?->getId() === $currentUser->getId();
    }

    private function canSelfUpdateAssignedTask(Task $task, User $currentUser): bool
    {
        return $task->getAssignedTo()?->getId() === $currentUser->getId();
    }

    private function canViewTask(Task $task, User $currentUser): bool
    {
        return $this->canManageTask($task, $currentUser) || $this->canSelfUpdateAssignedTask($task, $currentUser);
    }

    private function normalizeTaskStatus(string $status): string
    {
        $normalized = strtoupper(trim($status));
        $valid = ['TODO', 'IN_PROGRESS', 'IN_REVIEW', 'DONE'];
        return in_array($normalized, $valid, true) ? $normalized : 'TODO';
    }

    private function normalizePriority(string $priority): string
    {
        $normalized = strtoupper(trim($priority));
        $valid = ['LOW', 'MEDIUM', 'HIGH'];
        return in_array($normalized, $valid, true) ? $normalized : 'MEDIUM';
    }

    /** @return array<string, mixed> */
    private function serializeTask(Task $task): array
    {
        return [
            'id' => $task->getId(),
            'project_id' => $task->getProject()?->getId(),
            'assigned_to' => $task->getAssignedTo()?->getId(),
            'title' => $task->getTitle(),
            'description' => $task->getDescription(),
            'status' => $task->getStatus() ?? 'TODO',
            'priority' => $task->getPriority() ?? 'MEDIUM',
            'due_date' => $task->getDueDate()?->format('Y-m-d'),
            'created_at' => $task->getCreatedAt()?->format('Y-m-d H:i:s'),
            'submission_text' => $task->getSubmissionText(),
            'submission_file' => $task->getSubmissionFile(),
            'review_status' => $task->getReviewStatus(),
            'review_rating' => $task->getReviewRating(),
            'review_feedback' => $task->getReviewFeedback(),
            'review_date' => $task->getReviewDate()?->format('Y-m-d H:i:s'),
        ];
    }
}
