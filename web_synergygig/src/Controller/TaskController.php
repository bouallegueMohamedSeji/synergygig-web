<?php

namespace App\Controller;

use App\Entity\Task;
use App\Form\TaskType;
use App\Repository\TaskRepository;
use App\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Knp\Component\Pager\PaginatorInterface;

#[Route('/tasks')]
class TaskController extends AbstractController
{
    #[Route('/', name: 'app_task_index')]
    public function index(Request $request, TaskRepository $repo, PaginatorInterface $paginator): Response
    {
        $qb = $repo->createQueryBuilder('t')
            ->leftJoin('t.project', 'p')->addSelect('p')
            ->orderBy('t.id', 'DESC');

        $user = $this->getUser();
        if (!$this->isGranted('ROLE_HR')) {
            if ($this->isGranted('ROLE_PROJECT_OWNER')) {
                // PROJECT_OWNER sees tasks in their own projects
                $qb->andWhere('p.owner = :owner')->setParameter('owner', $user);
            } else {
                // EMPLOYEE / GIG_WORKER see only tasks assigned to them
                $qb->andWhere('t.assignedTo = :user')->setParameter('user', $user);
            }
        }

        $q = $request->query->get('q');
        if ($q) {
            $qb->andWhere('LOWER(t.title) LIKE :q')->setParameter('q', '%' . mb_strtolower((string) $q) . '%');
        }

        $status = $request->query->get('status');
        if ($status) {
            $qb->andWhere('t.status = :status')->setParameter('status', $status);
        }

        $pagination = $paginator->paginate($qb, $request->query->getInt('page', 1), 15);

        return $this->render('task/index.html.twig', [
            'tasks' => $pagination,
            'pagination' => $pagination,
        ]);
    }

    #[Route('/new', name: 'app_task_new')]
    #[IsGranted('ROLE_PROJECT_OWNER')]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $task = new Task();
        $form = $this->createForm(TaskType::class, $task);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($task);
            $em->flush();
            $this->addFlash('success', 'Task created.');
            return $this->redirectToRoute('app_task_index');
        }

        return $this->render('task/form.html.twig', [
            'form' => $form->createView(),
            'is_edit' => false,
        ]);
    }

    #[Route('/{id}', name: 'app_task_show', requirements: ['id' => '\d+'])]
    public function show(Task $task): Response
    {
        return $this->render('task/show.html.twig', [
            'task' => $task,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_task_edit', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_PROJECT_OWNER')]
    public function edit(Request $request, Task $task, EntityManagerInterface $em): Response
    {
        if (!$this->isGranted('ROLE_ADMIN') && $task->getProject()?->getOwner() !== $this->getUser()) {
            throw $this->createAccessDeniedException('You can only edit tasks in your own projects.');
        }
        $form = $this->createForm(TaskType::class, $task);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Task updated.');
            return $this->redirectToRoute('app_task_index');
        }

        return $this->render('task/form.html.twig', [
            'form' => $form->createView(),
            'is_edit' => true,
            'task' => $task,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_task_delete', methods: ['POST'])]
    #[IsGranted('ROLE_PROJECT_OWNER')]
    public function delete(Request $request, Task $task, EntityManagerInterface $em): Response
    {
        if (!$this->isGranted('ROLE_ADMIN') && $task->getProject()?->getOwner() !== $this->getUser()) {
            throw $this->createAccessDeniedException('You can only delete tasks in your own projects.');
        }
        if ($this->isCsrfTokenValid('delete' . $task->getId(), (string) $request->request->get('_token'))) {
            $em->remove($task);
            $em->flush();
            $this->addFlash('success', 'Task deleted.');
        }
        return $this->redirectToRoute('app_task_index');
    }

    #[Route('/{id}/submit', name: 'app_task_submit', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function submit(Request $request, Task $task, EntityManagerInterface $em, ValidatorInterface $validator): Response
    {
        // Only the assigned user can submit work
        if ($task->getAssignedTo() !== $this->getUser()) {
            $this->addFlash('error', 'Only the assigned user can submit work for this task.');
            return $this->redirectToRoute('app_task_show', ['id' => $task->getId()]);
        }

        if (!$this->isCsrfTokenValid('submit' . $task->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token.');
            return $this->redirectToRoute('app_task_show', ['id' => $task->getId()]);
        }

        $submissionText = trim((string) $request->request->get('submission_text', ''));

        $violations = $validator->validate($submissionText, [
            new Assert\NotBlank(['message' => 'Submission text is required.']),
            new Assert\Length([
                'min' => 10, 'minMessage' => 'Submission must be at least {{ limit }} characters.',
                'max' => 5000, 'maxMessage' => 'Submission cannot exceed {{ limit }} characters.',
            ]),
        ]);

        if (count($violations) > 0) {
            foreach ($violations as $v) {
                $this->addFlash('error', $v->getMessage());
            }
            return $this->redirectToRoute('app_task_show', ['id' => $task->getId()]);
        }

        $task->setSubmissionText($submissionText);
        $task->setStatus('IN_REVIEW');

        /** @var \Symfony\Component\HttpFoundation\File\UploadedFile|null $file */
        $file = $request->files->get('submission_file');
        if ($file) {
            $allowed = ['pdf', 'doc', 'docx', 'txt', 'zip', 'png', 'jpg', 'jpeg'];
            $ext = strtolower($file->guessExtension() ?? $file->getClientOriginalExtension());
            if (!in_array($ext, $allowed, true)) {
                $this->addFlash('error', 'Invalid file type. Allowed: ' . implode(', ', $allowed));
                return $this->redirectToRoute('app_task_show', ['id' => $task->getId()]);
            }
            if ($file->getSize() > 10 * 1024 * 1024) {
                $this->addFlash('error', 'File size must not exceed 10 MB.');
                return $this->redirectToRoute('app_task_show', ['id' => $task->getId()]);
            }

            $filename = 'task_' . $task->getId() . '_' . uniqid() . '.' . $ext;
            $projectDir = $this->getParameter('kernel.project_dir');
            if (!is_string($projectDir)) {
                throw new \RuntimeException('Invalid project directory configuration.');
            }
            $uploadDir = $projectDir . '/public/uploads/tasks';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0775, true);
            }
            try {
                $file->move($uploadDir, $filename);
                $task->setSubmissionFile('uploads/tasks/' . $filename);
            } catch (FileException $e) {
                $this->addFlash('error', 'File upload failed.');
                return $this->redirectToRoute('app_task_show', ['id' => $task->getId()]);
            }
        }

        $em->flush();
        $this->addFlash('success', 'Work submitted for review.');
        return $this->redirectToRoute('app_task_show', ['id' => $task->getId()]);
    }

    #[Route('/{id}/review', name: 'app_task_review', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_PROJECT_OWNER')]
    public function review(Request $request, Task $task, EntityManagerInterface $em, ValidatorInterface $validator): Response
    {
        // Only the project owner can review tasks
        if (!$this->isGranted('ROLE_ADMIN') && $task->getProject()?->getOwner() !== $this->getUser()) {
            throw $this->createAccessDeniedException('Only the project owner can review this task.');
        }

        if (!$this->isCsrfTokenValid('review' . $task->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token.');
            return $this->redirectToRoute('app_task_show', ['id' => $task->getId()]);
        }

        $reviewStatus = $request->request->get('review_status', '');
        $reviewRating = (int) $request->request->get('review_rating', 0);
        $reviewFeedback = trim((string) $request->request->get('review_feedback', ''));

        // Validate review status
        if (!in_array($reviewStatus, ['APPROVED', 'NEEDS_REVISION', 'REJECTED'], true)) {
            $this->addFlash('error', 'Invalid review status.');
            return $this->redirectToRoute('app_task_show', ['id' => $task->getId()]);
        }

        // Validate rating
        if ($reviewRating < 1 || $reviewRating > 5) {
            $this->addFlash('error', 'Rating must be between 1 and 5.');
            return $this->redirectToRoute('app_task_show', ['id' => $task->getId()]);
        }

        // Validate feedback
        $violations = $validator->validate($reviewFeedback, [
            new Assert\NotBlank(['message' => 'Feedback is required.']),
            new Assert\Length([
                'min' => 5, 'minMessage' => 'Feedback must be at least {{ limit }} characters.',
                'max' => 2000, 'maxMessage' => 'Feedback cannot exceed {{ limit }} characters.',
            ]),
        ]);
        if (count($violations) > 0) {
            foreach ($violations as $v) {
                $this->addFlash('error', $v->getMessage());
            }
            return $this->redirectToRoute('app_task_show', ['id' => $task->getId()]);
        }

        $task->setReviewStatus($reviewStatus);
        $task->setReviewRating($reviewRating);
        $task->setReviewFeedback($reviewFeedback);
        $task->initReviewDate(new \DateTime());

        if ($reviewStatus === 'APPROVED') {
            $task->setStatus('DONE');
        } elseif ($reviewStatus === 'NEEDS_REVISION') {
            $task->setStatus('IN_PROGRESS');
        }

        $em->flush();
        $this->addFlash('success', 'Review submitted.');
        return $this->redirectToRoute('app_task_show', ['id' => $task->getId()]);
    }
}
