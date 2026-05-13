<?php

namespace App\Controller;

use App\Entity\Post;
use App\Repository\TaskRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/employee-of-month')]
#[IsGranted('ROLE_HR')]
class EmployeeOfMonthController extends AbstractController
{
    #[Route('', name: 'app_employee_of_month')]
    public function index(TaskRepository $taskRepo, UserRepository $userRepo): Response
    {
        // Score: HIGH=3, MEDIUM=2, LOW=1 per completed task
        $weights = ['HIGH' => 3, 'MEDIUM' => 2, 'LOW' => 1];

        $tasks = $taskRepo->findBy(['status' => 'DONE']);

        $scores = [];
        foreach ($tasks as $task) {
            $assignee = $task->getAssignedTo();
            if (!$assignee) continue;

            $uid = $assignee->getId();
            if (!isset($scores[$uid])) {
                $scores[$uid] = [
                    'user' => $assignee,
                    'name' => $assignee->getFirstName() . ' ' . $assignee->getLastName(),
                    'department' => $assignee->getDepartment() ? $assignee->getDepartment()->getName() : 'N/A',
                    'done' => 0,
                    'highPriority' => 0,
                    'score' => 0,
                ];
            }
            $scores[$uid]['done']++;
            $priority = strtoupper($task->getPriority() ?? 'LOW');
            if ($priority === 'HIGH') {
                $scores[$uid]['highPriority']++;
            }
            $scores[$uid]['score'] += $weights[$priority] ?? 1;
        }

        // Sort by score descending
        usort($scores, fn($a, $b) => $b['score'] <=> $a['score']);
        $leaderboard = array_slice($scores, 0, 10);

        // Rank
        foreach ($leaderboard as $i => &$entry) {
            $entry['rank'] = $i + 1;
        }
        unset($entry);

        $winner = $leaderboard[0] ?? null;
        $announcement = '';
        if ($winner) {
            $announcement = "🏆 Employee of the Month: {$winner['name']} ({$winner['department']})\n\n"
                . "Completed {$winner['done']} tasks ({$winner['highPriority']} high-priority) with a total score of {$winner['score']} points.\n\n"
                . "Congratulations on your outstanding performance! Keep up the great work! 🎉";
        }

        return $this->render('employee_of_month/index.html.twig', [
            'leaderboard' => $leaderboard,
            'winner' => $winner,
            'announcement' => $announcement,
        ]);
    }

    #[Route('/post', name: 'app_employee_of_month_post', methods: ['POST'])]
    public function postToCommunity(Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('eom_post', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_employee_of_month');
        }

        $text = trim((string) $request->request->get('announcement', ''));
        if (strlen($text) < 10) {
            $this->addFlash('error', 'Announcement text is too short.');
            return $this->redirectToRoute('app_employee_of_month');
        }

        $post = new Post();
        $currentUser = $this->getUser();
        if (!$currentUser instanceof \App\Entity\User) {
            throw $this->createAccessDeniedException('Invalid authenticated user.');
        }
        $post->initAuthor($currentUser);
        $post->setContent($text);
        $post->setVisibility('PUBLIC');
        $em->persist($post);
        $em->flush();

        $this->addFlash('success', 'Announcement posted to Community!');
        return $this->redirectToRoute('app_employee_of_month');
    }
}
