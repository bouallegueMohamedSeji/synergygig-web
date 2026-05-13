<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\ExternalJobService;
use App\Service\ExchangeRateService;
use App\Service\HunterService;
use App\Service\CvJobMatchService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class ExternalJobController extends AbstractController
{
    public function __construct(
        private ExternalJobService $jobService,
        private ExchangeRateService $exchangeRateService,
        private HunterService $hunterService,
        private CvJobMatchService $cvJobMatch,
    ) {}

    // ── External Job Feeds ──

    #[Route('/external-jobs', name: 'app_external_jobs')]
    public function externalJobs(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_GIG_WORKER');

        return $this->render('external_jobs/index.html.twig', [
            'categories' => $this->jobService->getCategories(),
        ]);
    }

    #[Route('/external-jobs/search', name: 'app_external_jobs_search', methods: ['POST'])]
    public function searchJobs(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $data     = json_decode($request->getContent(), true);
        $data     = is_array($data) ? $data : [];
        $query    = trim((string) ($data['query'] ?? ''));
        $source   = (string) ($data['source'] ?? 'all');
        $category = (string) ($data['category'] ?? '');

        if (strlen($query) < 2) {
            return $this->json(['error' => 'Please enter at least 2 characters.'], 422);
        }

        $jobs = $this->jobService->search($query, $source, $category);

        return $this->json([
            'results' => $jobs,
            'count'   => count($jobs),
        ]);
    }

    #[Route('/external-jobs/score', name: 'app_external_jobs_score', methods: ['POST'])]
    public function scoreJobs(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['hasCV' => false, 'scores' => []]);
        }
        $cvText = $user->getCvSkillsText() ?? ($user->getBio() ?? '');

        if ($cvText === '') {
            return $this->json(['hasCV' => false, 'scores' => []]);
        }

        $data = json_decode($request->getContent(), true);
        $data = is_array($data) ? $data : [];
        $jobs = $data['jobs'] ?? [];

        $scores = [];
        foreach ($jobs as $i => $job) {
            $result = $this->cvJobMatch->score(
                $cvText,
                $job['title'] ?? '',
                ($job['description'] ?? '') . ' ' . implode(' ', $job['tags'] ?? [])
            );
            $scores[$i] = $result;
        }

        return $this->json(['hasCV' => true, 'scores' => $scores]);
    }

    // ── Currency Converter ──

    #[Route('/currency-converter', name: 'app_currency_converter')]
    public function currencyConverter(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_GIG_WORKER');

        return $this->render('currency_converter/index.html.twig', [
            'currencies' => $this->exchangeRateService->getCommonCurrencies(),
        ]);
    }

    #[Route('/currency/convert', name: 'app_currency_convert', methods: ['POST'])]
    public function convert(Request $request): JsonResponse
    {
        $data   = json_decode($request->getContent(), true);
        $data   = is_array($data) ? $data : [];
        $amount = (float) ($data['amount'] ?? 0);
        $from   = strtoupper(trim((string) ($data['from'] ?? 'USD')));
        $to     = strtoupper(trim((string) ($data['to'] ?? 'TND')));

        if ($amount <= 0) {
            return $this->json(['error' => 'Amount must be positive.'], 422);
        }

        $converted = $this->exchangeRateService->convert($amount, $from, $to);

        if ($converted === null) {
            return $this->json(['error' => 'Conversion failed. Check API key or currency codes.'], 503);
        }

        $rates = $this->exchangeRateService->getRates($from);

        return $this->json([
            'amount'    => $amount,
            'from'      => $from,
            'to'        => $to,
            'result'    => $converted,
            'rate'      => $rates['rates'][$to] ?? null,
            'updated'   => $rates['updated'] ?? '',
        ]);
    }

    // ── Email Verification (Hunter.io) ──

    #[Route('/verify-email', name: 'app_verify_email_hunter', methods: ['POST'])]
    public function verifyEmail(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_GIG_WORKER');

        $data  = json_decode($request->getContent(), true);
        $data  = is_array($data) ? $data : [];
        $email = trim((string) ($data['email'] ?? ''));

        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['error' => 'Please provide a valid email address.'], 422);
        }

        $result = $this->hunterService->verifyEmail($email);

        if ($result === null) {
            return $this->json(['error' => 'Email verification service unavailable.'], 503);
        }

        return $this->json($result);
    }
}
