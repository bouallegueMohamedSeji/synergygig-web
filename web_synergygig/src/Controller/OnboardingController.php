<?php

namespace App\Controller;

use App\Service\AIService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/onboarding')]
class OnboardingController extends AbstractController
{
    #[Route('', name: 'app_onboarding')]
    public function index(Request $request): Response
    {
        $checklist = $this->getChecklist();
        $session = $request->getSession();
        $checked = $session->get('onboarding_checked', []);

        return $this->render('onboarding/index.html.twig', [
            'checklist' => $checklist,
            'checked' => $checked,
        ]);
    }

    #[Route('/toggle', name: 'app_onboarding_toggle', methods: ['POST'])]
    public function toggle(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $data = is_array($data) ? $data : [];
        $key = (string) ($data['key'] ?? '');

        $session = $request->getSession();
        $checked = $session->get('onboarding_checked', []);

        if (in_array($key, $checked, true)) {
            $checked = array_values(array_diff($checked, [$key]));
        } else {
            $checked[] = $key;
        }

        $session->set('onboarding_checked', $checked);

        $total = 0;
        foreach ($this->getChecklist() as $section) {
            $total += count($section['items']);
        }

        return $this->json([
            'checked' => count($checked),
            'total' => $total,
            'progress' => $total > 0 ? round(count($checked) / $total * 100) : 0,
        ]);
    }

    #[Route('/ask', name: 'app_onboarding_ask', methods: ['POST'])]
    public function ask(Request $request, AIService $ai): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $data = is_array($data) ? $data : [];
        $question = trim((string) ($data['question'] ?? ''));

        if (strlen($question) < 3) {
            return $this->json(['error' => 'Please enter a question.'], 422);
        }

        $answer = $ai->chat(
            "You are an onboarding assistant for SynergyGig, a corporate HR platform. "
            . "Help new employees with questions about their first days: IT setup, team introductions, required training, "
            . "company policies, tools to learn, and general guidance. Be friendly and concise.",
            $question,
            0.6,
            512
        ) ?? "I'm unable to answer right now. Please ask your HR manager or team lead for help.";

        return $this->json(['answer' => $answer]);
    }

    /**
     * @return list<array{title: string, items: list<string>}>
     */
    private function getChecklist(): array
    {
        return [
            [
                'title' => '🖥️ Day 1 — IT & Access Setup',
                'items' => [
                    'Receive company email and credentials',
                    'Set up workstation / laptop',
                    'Install required software (IDE, Slack, etc.)',
                    'Get access to project repositories',
                    'Complete IT security training',
                ],
            ],
            [
                'title' => '👋 Week 1 — Meet Your Team',
                'items' => [
                    'Meet your direct manager',
                    'Introduction round with team members',
                    'Tour of office / virtual workspace',
                    'Attend team standup meeting',
                    'Review team documentation and wiki',
                ],
            ],
            [
                'title' => '📚 Week 1-2 — Required Training',
                'items' => [
                    'Complete HR policy orientation',
                    'Review code of conduct',
                    'Complete security awareness training',
                    'Enroll in role-specific training courses',
                    'Take platform walkthrough (SynergyGig)',
                ],
            ],
            [
                'title' => '🚀 Week 2 — Getting Productive',
                'items' => [
                    'Set up development environment',
                    'Complete first assigned task',
                    'Submit first code review / deliverable',
                    'Join relevant communication channels',
                    'Review project roadmap and backlog',
                ],
            ],
            [
                'title' => '📋 Month 1 — Wrap Up',
                'items' => [
                    '30-day check-in with manager',
                    'Set personal goals for next quarter',
                    'Give onboarding feedback to HR',
                    'Update profile and skills in SynergyGig',
                ],
            ],
        ];
    }
}
