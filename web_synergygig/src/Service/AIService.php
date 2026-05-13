<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Multi-provider AI service with cascading fallback.
 * Mirrors the Java ZAIService architecture:
 *   1. Z.AI (GLM-5 / GLM-4.7-Flash)
 *   2. Groq (Llama 3.3-70B / 3.1-8B)
 *   3. OpenCode Zen (GLM-5 / Qwen3-coder)
 *   4. OpenRouter (free Llama / Gemma)
 */
class AIService
{
    private array $providers;

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        ?string $zaiApiKey = '',
        ?string $zaiApiKeyBackup = '',
        ?string $groqApiKey = '',
        ?string $openrouterApiKey = '',
        ?string $opencodeApiKey = '',
    ) {
        $zaiApiKey        = $zaiApiKey ?? '';
        $zaiApiKeyBackup  = $zaiApiKeyBackup ?? '';
        $groqApiKey       = $groqApiKey ?? '';
        $openrouterApiKey = $openrouterApiKey ?? '';
        $opencodeApiKey   = $opencodeApiKey ?? '';
        $this->providers = [
            // Primary Z.AI key (working)
            ['name' => 'Z.AI glm-5',              'url' => 'https://api.z.ai/api/paas/v4/chat/completions',   'key' => $zaiApiKey,        'model' => 'glm-5'],
            ['name' => 'Z.AI glm-4.7-flash',      'url' => 'https://api.z.ai/api/paas/v4/chat/completions',   'key' => $zaiApiKey,        'model' => 'glm-4.7-flash'],
            // Backup Z.AI key
            ['name' => 'Z.AI backup glm-5',       'url' => 'https://api.z.ai/api/paas/v4/chat/completions',   'key' => $zaiApiKeyBackup,  'model' => 'glm-5'],
            ['name' => 'Z.AI backup glm-4.7-flash','url' => 'https://api.z.ai/api/paas/v4/chat/completions',  'key' => $zaiApiKeyBackup,  'model' => 'glm-4.7-flash'],
            // Groq (working)
            ['name' => 'Groq llama-3.3-70b',      'url' => 'https://api.groq.com/openai/v1/chat/completions', 'key' => $groqApiKey,       'model' => 'llama-3.3-70b-versatile'],
            ['name' => 'Groq llama-3.1-8b',       'url' => 'https://api.groq.com/openai/v1/chat/completions', 'key' => $groqApiKey,       'model' => 'llama-3.1-8b-instant'],
            // OpenRouter (429 — may recover)
            ['name' => 'OpenRouter llama-free',   'url' => 'https://openrouter.ai/api/v1/chat/completions',   'key' => $openrouterApiKey, 'model' => 'meta-llama/llama-3.3-70b-instruct:free'],
            ['name' => 'OpenRouter gemma-free',   'url' => 'https://openrouter.ai/api/v1/chat/completions',   'key' => $openrouterApiKey, 'model' => 'google/gemma-2-9b-it:free'],
        ];
    }

    /**
     * Send a chat completion request with automatic provider fallback.
     * Returns the assistant's message content, or null if all providers fail.
     */
    public function chat(string $systemPrompt, string $userMessage, float $temperature = 0.7, int $maxTokens = 2048): ?string
    {
        return $this->runChatRequest($systemPrompt, $userMessage, $temperature, $maxTokens);
    }

    /**
     * Use a short AI deadline for request/response paths where we prefer a fast local fallback over waiting.
     */
    public function chatFast(string $systemPrompt, string $userMessage, float $temperature = 0.7, int $maxTokens = 2048): ?string
    {
        return $this->runChatRequest($systemPrompt, $userMessage, $temperature, $maxTokens, [
            'preferredProviders' => [
                'Groq llama-3.3-70b',
                'Groq llama-3.1-8b',
                'Z.AI glm-4.7-flash',
            ],
            'timeout' => 4,
            'totalTimeout' => 10,
            'maxProviders' => 3,
        ]);
    }

    private function runChatRequest(string $systemPrompt, string $userMessage, float $temperature, int $maxTokens, array $options = []): ?string
    {
        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user',   'content' => $userMessage],
        ];

        $providers = $this->orderedProviders($options['preferredProviders'] ?? []);
        $requestTimeout = (float) ($options['timeout'] ?? 30);
        $totalTimeout = isset($options['totalTimeout']) ? (float) $options['totalTimeout'] : null;
        $maxProviders = isset($options['maxProviders']) ? (int) $options['maxProviders'] : null;
        $deadline = $totalTimeout !== null ? microtime(true) + $totalTimeout : null;
        $attempts = 0;

        foreach ($providers as $provider) {
            if (empty($provider['key'])) {
                continue;
            }

            if ($maxProviders !== null && $attempts >= $maxProviders) {
                break;
            }

            $timeout = $requestTimeout;
            if ($deadline !== null) {
                $remaining = $deadline - microtime(true);
                if ($remaining <= 0) {
                    break;
                }
                $timeout = min($timeout, max(1.0, $remaining));
            }

            $attempts++;

            try {
                $response = $this->httpClient->request('POST', $provider['url'], [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $provider['key'],
                        'Content-Type'  => 'application/json',
                    ],
                    'json' => [
                        'model'       => $provider['model'],
                        'messages'    => $messages,
                        'temperature' => $temperature,
                        'max_tokens'  => $maxTokens,
                    ],
                    'timeout' => $timeout,
                ]);

                $data = $response->toArray();
                $content = $data['choices'][0]['message']['content'] ?? null;

                if ($content) {
                    $this->logger->info('AI response from {provider}', ['provider' => $provider['name']]);
                    return $content;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('AI provider {provider} failed: {error}', [
                    'provider' => $provider['name'],
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        $this->logger->error('All AI providers failed.');
        return null;
    }

    private function orderedProviders(array $preferredProviders = []): array
    {
        if (empty($preferredProviders)) {
            return $this->providers;
        }

        $ordered = [];
        $seen = [];

        foreach ($preferredProviders as $providerName) {
            foreach ($this->providers as $provider) {
                if ($provider['name'] !== $providerName) {
                    continue;
                }

                $ordered[] = $provider;
                $seen[$provider['name']] = true;
                break;
            }
        }

        foreach ($this->providers as $provider) {
            if (isset($seen[$provider['name']])) {
                continue;
            }
            $ordered[] = $provider;
        }

        return $ordered;
    }

    /* ─── Convenience methods matching Java ZAIService ─── */

    public function reviewCode(string $code, string $language): string
    {
        $system = "You are an expert code reviewer. Analyze the code and return a structured review in Markdown with these sections:\n"
            . "1. **Overall Quality** (score /10)\n2. **Bugs & Issues**\n3. **Security Concerns**\n"
            . "4. **Performance**\n5. **Code Style & Best Practices**\n6. **Suggestions**\n"
            . "Be concise but thorough.";
        $user = "Language: {$language}\n\n```{$language}\n{$code}\n```";

        return $this->chat($system, $user) ?? $this->fallbackCodeReview($code, $language);
    }

    public function composeEmail(string $recipient, string $purpose, string $keyPoints, string $tone): string
    {
        $system = "You are a professional email composer for a corporate HR/project management platform called SynergyGig. "
            . "Write a complete, ready-to-send email. Tone: {$tone}. Include Subject line.";
        $user = "Recipient: {$recipient}\nPurpose: {$purpose}\nKey points:\n{$keyPoints}";

        return $this->chat($system, $user, 0.7, 1024) ?? $this->fallbackEmail($recipient, $purpose, $keyPoints, $tone);
    }

    public function summarizeMeeting(string $transcript): string
    {
        $system = "You are a meeting summarizer. Produce a structured Markdown summary with: "
            . "**Attendees**, **Key Discussion Points**, **Decisions Made**, **Action Items** (with owners if identifiable), "
            . "and **Follow-up Timeline**. Be concise.";

        return $this->chat($system, $transcript) ?? $this->fallbackMeetingSummary($transcript);
    }

    public function parseResume(string $text): array
    {
        $system = "You are an expert resume parser. Extract structured data from the resume text and return ONLY valid JSON with these keys:\n"
            . '{"name":"","email":"","phone":"","location":"","summary":"","skills":[],'
            . '"experience":[{"title":"","company":"","period":"","description":""}],'
            . '"education":[{"degree":"","institution":"","year":""}],'
            . '"certifications":[],"languages":[]}'
            . "\nReturn ONLY the JSON object, no markdown code fences.";

        $result = $this->chat($system, $text, 0.3, 2048);
        if ($result) {
            // Strip markdown fences if present
            $result = preg_replace('/^```(?:json)?\s*|```\s*$/m', '', trim($result)) ?? trim($result);
            $parsed = json_decode($result, true);
            if (is_array($parsed)) {
                return $parsed;
            }
        }

        return $this->fallbackResumeParse($text);
    }

    public function interviewQuestion(string $role, string $level, string $action, string $answer, int $qNum): array
    {
        if ($action === 'start') {
            $system = "You are an expert interview coach. Generate the first interview question for a {$level}-level {$role} position. "
                . "Return JSON: {\"type\":\"question\",\"message\":\"<markdown>\",\"question\":1}";
            $result = $this->chat($system, "Begin the mock interview.", 0.8, 512);
            if ($result) {
                $result = preg_replace('/^```(?:json)?\s*|```\s*$/m', '', trim($result)) ?? trim($result);
                $parsed = json_decode($result, true);
                if (is_array($parsed)) {
                    return $parsed;
                }
            }
        }

        if ($action === 'end') {
            $system = "You are an interview coach. Provide a performance summary for a {$level}-level {$role} interview "
                . "where the candidate completed {$qNum}/8 questions. Return JSON: {\"type\":\"summary\",\"message\":\"<markdown>\"}";
            $result = $this->chat($system, "Summarize the interview performance.", 0.7, 1024);
            if ($result) {
                $result = preg_replace('/^```(?:json)?\s*|```\s*$/m', '', trim($result)) ?? trim($result);
                $parsed = json_decode($result, true);
                if (is_array($parsed)) {
                    return $parsed;
                }
            }
        }

        if ($action === 'answer' || $action === 'skip') {
            $nextQ = min($qNum + 1, 8);
            $feedbackReq = $action === 'answer'
                ? "Rate this answer (1-5 stars) and give brief feedback, then ask question {$nextQ}."
                : "The candidate skipped. Give a sample answer, then ask question {$nextQ}.";

            $system = "You are an interview coach for a {$level}-level {$role} position. "
                . "{$feedbackReq} Return JSON: {\"type\":\"feedback\",\"message\":\"<markdown>\",\"question\":{$nextQ}}";
            $user = $action === 'answer' ? "Candidate's answer: {$answer}" : "Candidate skipped question {$qNum}.";

            $result = $this->chat($system, $user, 0.7, 1024);
            if ($result) {
                $result = preg_replace('/^```(?:json)?\s*|```\s*$/m', '', trim($result)) ?? trim($result);
                $parsed = json_decode($result, true);
                if (is_array($parsed)) {
                    return $parsed;
                }
            }
        }

        // Fallback
        return $this->fallbackInterview($role, $level, $action, $answer, $qNum);
    }

    public function chatHRPolicy(string $question, array $conversationHistory = []): string
    {
        $system = "You are an HR Policy Assistant for SynergyGig, a corporate HR and project management platform. "
            . "Answer questions about common HR policies: leave policies (annual: 21 days, sick: 15 days, maternity: 90 days, paternity: 14 days), "
            . "attendance rules, payroll procedures, training requirements, interview processes, contract management, "
            . "employee onboarding, performance reviews, and workplace conduct. "
            . "Be helpful, professional, and concise. If unsure, advise consulting the HR department directly.";

        $messages = [['role' => 'system', 'content' => $system]];
        foreach ($conversationHistory as $msg) {
            $messages[] = ['role' => $msg['role'], 'content' => $msg['content']];
        }
        $messages[] = ['role' => 'user', 'content' => $question];

        foreach ($this->providers as $provider) {
            if (empty($provider['key'])) {
                continue;
            }
            try {
                $response = $this->httpClient->request('POST', $provider['url'], [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $provider['key'],
                        'Content-Type'  => 'application/json',
                    ],
                    'json' => [
                        'model'       => $provider['model'],
                        'messages'    => $messages,
                        'temperature' => 0.5,
                        'max_tokens'  => 1024,
                    ],
                    'timeout' => 30,
                ]);
                $data = $response->toArray();
                $content = $data['choices'][0]['message']['content'] ?? null;
                if ($content) {
                    return $content;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('AI provider {provider} failed for HR chat: {error}', [
                    'provider' => $provider['name'],
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        return "I'm sorry, I'm unable to process your question right now. Please try again later or contact the HR department directly.";
    }

    public function scanJobs(string $skills, string $location, string $level): string
    {
        $system = "You are a job market analyst AI. Given a candidate's skills, preferred location, and experience level, "
            . "generate a realistic list of 8-10 matching job opportunities in Markdown table format with columns: "
            . "Company | Position | Location | Match % | Salary Range | Key Requirements. "
            . "Also add a brief market analysis section and tips for the candidate.";
        $user = "Skills: {$skills}\nPreferred Location: {$location}\nExperience Level: {$level}";

        return $this->chat($system, $user, 0.8, 2048)
            ?? "Unable to scan jobs at this time. Please try again later.";
    }

    public function generateContractDraft(
        string $candidateName,
        string $offerTitle,
        string $contractType = 'GIG',
        ?float $amount = null,
        ?\DateTimeInterface $startDate = null,
        ?\DateTimeInterface $endDate = null
    ): string {
        $system = "You are a legal contract drafting assistant for SynergyGig, a platform connecting gig workers with employers. "
            . "Draft a professional, legally-sound {$contractType} contract. "
            . "Structure it with clear numbered sections: "
            . "1. Parties & Recitals  2. Scope of Work  3. Duration & Schedule  4. Compensation & Payment Terms  "
            . "5. Intellectual Property  6. Confidentiality & Non-Disclosure  7. Termination  "
            . "8. Dispute Resolution & Governing Law  9. General Provisions. "
            . "Use plain English. Add [PLACEHOLDER] markers where specific values need HR review. "
            . "Do NOT use markdown code fences — return plain text only.";

        $amountStr = $amount ? '$' . number_format($amount, 2) . ' USD' : '[RATE TO BE AGREED]';
        $startStr  = $startDate ? $startDate->format('F d, Y') : '[START DATE]';
        $endStr    = $endDate   ? $endDate->format('F d, Y')   : '[END DATE / AT-WILL]';

        $user = "Contract Type: {$contractType}\n"
            . "Candidate (Worker): {$candidateName}\n"
            . "Employer: SynergyGig Platform / [CLIENT COMPANY NAME]\n"
            . "Position / Offer: {$offerTitle}\n"
            . "Compensation: {$amountStr}\n"
            . "Start Date: {$startStr}\n"
            . "End Date: {$endStr}\n\n"
            . "Draft the complete contract now.";

        return $this->chat($system, $user, 0.35, 3500)
            ?? $this->fallbackContractDraft($candidateName, $offerTitle, $contractType, $amount, $startDate, $endDate);
    }

    // ──────────────────────────────────────────────────────────
    //  PROJECT AI FEATURES (ported from Java ZAIService)
    // ──────────────────────────────────────────────────────────

    /**
     * Generate 6-10 smart tasks from project name + description.
     * Returns JSON array: [{"title","description","priority"}]
     */
    public function generateProjectTasks(string $projectName, string $description, int $teamSize = 1): array
    {
        $system = "You are an expert project manager. Based on the project info, generate 6-10 smart, actionable tasks.\n"
            . "Consider the team size and project scope. Prioritize correctly.\n"
            . "Return ONLY a valid JSON array (no markdown/code fences):\n"
            . '[{"title": "<task title>", "description": "<1-2 sentence description>", "priority": "HIGH|MEDIUM|LOW"}]';

        $user = "Project: {$projectName}\nDescription: " . ($description ?: 'No description') . "\nTeam size: {$teamSize}";

        $result = $this->chat($system, $user, 0.7, 2048);
        if ($result) {
            $result = preg_replace('/^```(?:json)?\s*|```\s*$/m', '', trim($result)) ?? trim($result);
            $parsed = json_decode($result, true);
            if (is_array($parsed) && !empty($parsed)) {
                return $parsed;
            }
        }

        // Keyword-based fallback
        return $this->fallbackGenerateTasks($projectName, $description);
    }

    private function fallbackGenerateTasks(string $projectName, string $description): array
    {
        $combined = strtolower($projectName . ' ' . $description);
        if (str_contains($combined, 'web') || str_contains($combined, 'app') || str_contains($combined, 'site')) {
            return [
                ['title' => 'Define project requirements and scope', 'description' => 'Document functional and non-functional requirements with stakeholders.', 'priority' => 'HIGH'],
                ['title' => 'Set up development environment', 'description' => 'Configure repositories, CI/CD pipeline, and local dev tools.', 'priority' => 'HIGH'],
                ['title' => 'Design system architecture', 'description' => 'Create architecture diagrams covering database, backend, and frontend layers.', 'priority' => 'HIGH'],
                ['title' => 'Implement core backend API', 'description' => 'Build primary REST endpoints for main entities.', 'priority' => 'HIGH'],
                ['title' => 'Design and build UI components', 'description' => 'Create reusable frontend components following design guidelines.', 'priority' => 'MEDIUM'],
                ['title' => 'Write unit and integration tests', 'description' => 'Cover critical paths with automated tests targeting 80% coverage.', 'priority' => 'MEDIUM'],
                ['title' => 'Conduct security audit', 'description' => 'Review authentication, authorization, and input validation.', 'priority' => 'HIGH'],
                ['title' => 'Deploy to staging environment', 'description' => 'Configure staging server and deploy application for QA testing.', 'priority' => 'MEDIUM'],
            ];
        }
        return [
            ['title' => 'Project planning and kickoff', 'description' => 'Define goals, timeline, and team responsibilities.', 'priority' => 'HIGH'],
            ['title' => 'Requirements gathering', 'description' => 'Collect and document all stakeholder requirements.', 'priority' => 'HIGH'],
            ['title' => 'Research and analysis', 'description' => 'Investigate technical options and competitive landscape.', 'priority' => 'MEDIUM'],
            ['title' => 'Initial implementation', 'description' => 'Build the core functionality based on requirements.', 'priority' => 'HIGH'],
            ['title' => 'Testing and quality assurance', 'description' => 'Verify all features work as expected and fix defects.', 'priority' => 'MEDIUM'],
            ['title' => 'Documentation', 'description' => 'Write user and technical documentation.', 'priority' => 'LOW'],
            ['title' => 'Deployment and launch', 'description' => 'Deploy to production and monitor for issues.', 'priority' => 'HIGH'],
        ];
    }

    /**
     * Plan a sprint: assigns story points and selects tasks that fit capacity.
     * $tasksJson: JSON array [{id, title, status, priority}]
     * Returns JSON: {tasks:[{id,title,points,reason}], total_points, capacity_points, recommended_ids, warnings}
     */
    public function planSprint(string $tasksJson, int $teamSize, int $sprintDays): ?string
    {
        $system = "You are an agile coach. Plan a sprint using Fibonacci estimation (1/2/3/5/8/13).\n"
            . "Calculate capacity: team_size × sprint_days × 6 productive hours. Target 70-80% capacity.\n"
            . "Return ONLY valid JSON:\n"
            . '{"tasks": [{"id": <id>, "title": "<title>", "points": <1-13>, "reason": "<why>"}],'
            . ' "total_points": <sum>, "capacity_points": <capacity>, "recommended_ids": [<ids that fit>],'
            . ' "warnings": ["<dependency or risk warnings>"]}';

        $user = "Team size: {$teamSize}\nSprint days: {$sprintDays}\nTasks:\n{$tasksJson}";

        $result = $this->chat($system, $user, 0.5, 2048);
        if ($result) {
            return preg_replace('/^```(?:json)?\s*|```\s*$/m', '', trim($result));
        }
        return null;
    }

    /**
     * Prepare a meeting agenda from project state.
     * Returns formatted text (not JSON).
     */
    public function prepMeeting(string $projectName, string $tasksText, string $teamText): ?string
    {
        $system = "You are a meeting preparation assistant. Generate a structured meeting agenda including:\n"
            . "1. Numbered agenda items (max 8)\n"
            . "2. Risk alerts (overdue tasks, blocked items) marked with ⚠\n"
            . "3. Talking points per team member\n"
            . "4. Suggested time allocation per item\n"
            . "Return formatted text (not JSON), using clear headings and bullet points.";

        $user = "Project: {$projectName}\nTasks:\n{$tasksText}\nTeam:\n{$teamText}\n\nGenerate the meeting agenda.";

        return $this->chat($system, $user, 0.7, 1536)
            ?? "Unable to prepare meeting agenda. Please try again.";
    }

    /**
     * Decision helper: weighted criteria scoring.
     * Returns formatted analysis text with comparison table and recommendation.
     */
    public function helpDecide(string $question, string $options, string $criteria): ?string
    {
        $system = "You are a decision coach. Apply weighted criteria scoring to compare options.\n"
            . "Process: 1) Evaluate each option against each criterion (1-10). 2) Apply weights. 3) Sum scores.\n"
            . "4) Check for biases. 5) Recommend with confidence (High/Medium/Low).\n"
            . "Return formatted text with: comparison table, scores, bias check, recommendation.";

        $user = "Decision: {$question}\nOptions: {$options}\nCriteria: {$criteria}";

        return $this->chat($system, $user, 0.6, 1536)
            ?? "Unable to analyze decision. Please try again.";
    }

    private function fallbackContractDraft(
        string $candidateName,
        string $offerTitle,
        string $contractType,
        ?float $amount,
        ?\DateTimeInterface $startDate,
        ?\DateTimeInterface $endDate
    ): string {
        $amountStr = $amount ? '$' . number_format($amount, 2) . ' USD' : '[RATE TO BE AGREED]';
        $startStr  = $startDate ? $startDate->format('F d, Y') : '[START DATE]';
        $endStr    = $endDate   ? $endDate->format('F d, Y')   : '[END DATE]';
        $date      = date('F d, Y');

        return <<<TXT
{$contractType} SERVICES AGREEMENT

Effective Date: {$date}

1. PARTIES & RECITALS
   This {$contractType} Services Agreement ("Agreement") is entered into between:
   Worker: {$candidateName} ("Contractor")
   Client: [CLIENT COMPANY NAME] ("Client"), facilitated through SynergyGig Platform.

2. SCOPE OF WORK
   Contractor agrees to perform services related to: {$offerTitle}
   Specific deliverables, milestones, and acceptance criteria shall be documented in a Statement of Work ("SOW") attached hereto.

3. DURATION & SCHEDULE
   Start Date: {$startStr}
   End Date:   {$endStr}
   This Agreement may be extended by mutual written consent.

4. COMPENSATION & PAYMENT TERMS
   Rate: {$amountStr}
   Payment Schedule: [NET-30 / MILESTONE-BASED — specify]
   Invoices submitted by Contractor within 5 business days of milestone completion.

5. INTELLECTUAL PROPERTY
   All work product created under this Agreement shall be considered work-for-hire and shall vest exclusively in Client upon full payment, unless otherwise agreed in writing.

6. CONFIDENTIALITY & NON-DISCLOSURE
   Contractor agrees not to disclose any proprietary or confidential information of Client to third parties during or after the term of this Agreement.

7. TERMINATION
   Either party may terminate this Agreement with 14 days written notice. Client may terminate immediately for material breach.

8. DISPUTE RESOLUTION & GOVERNING LAW
   This Agreement shall be governed by the laws of [JURISDICTION]. Disputes shall be resolved by binding arbitration before litigation.

9. GENERAL PROVISIONS
   This Agreement constitutes the entire understanding between the parties. Amendments must be in writing signed by both parties.

SIGNATURES

Worker: ___________________________  Date: ___________
{$candidateName}

Client: ___________________________  Date: ___________
[Authorized Representative]

*(AI providers unavailable — standard template used. Review all [PLACEHOLDER] values before sending.)*
TXT;
    }

    /* ─── Fallback generators (used when all AI providers fail) ─── */

    private function fallbackCodeReview(string $code, string $lang): string
    {
        $lines = substr_count($code, "\n") + 1;
        $hasFunc = preg_match('/function\s|def\s|void\s|public\s|private\s/', $code);
        $hasTry  = preg_match('/try\s*\{|except|catch\s*\(/', $code);
        $hasCom  = preg_match('/\/\/|#|\/\*/', $code);
        $score   = min(10, max(3, 5 + ($hasFunc ? 1 : 0) + ($hasTry ? 1 : -1) + ($hasCom ? 1 : 0)));

        return "## Code Review — {$lang}\n\n### Overall Quality: **{$score}/10**\n"
            . "Analyzed **{$lines} lines** of {$lang}.\n\n*(AI providers unavailable — basic static analysis used)*\n\n"
            . "### Bugs & Issues\n" . ($hasTry ? "- Error handling detected.\n" : "- No error handling found.\n")
            . "\n### Security\n" . (preg_match('/eval|exec|innerHTML/', $code) ? "- Potential security risk detected.\n" : "- No immediate issues.\n")
            . "\n### Suggestions\n1. Add input validation.\n2. Add unit tests.\n";
    }

    private function fallbackEmail(string $to, string $purpose, string $points, string $tone): string
    {
        $g = match ($tone) { 'Formal' => "Dear {$to},", 'Casual' => "Hey {$to},", 'Urgent' => "URGENT — {$to},", default => "Hi {$to}," };
        $c = match ($tone) { 'Formal' => "Yours sincerely,", 'Casual' => "Cheers,", default => "Best regards," };
        $body = "Subject: Regarding {$purpose}\n\n{$g}\n\nI am writing regarding {$purpose}.\n\n";
        if ($points) {
            foreach (explode("\n", $points) as $p) { if (trim($p)) $body .= "• " . trim($p) . "\n"; }
        }
        return $body . "\n{$c}\n[Your Name]\n\n*(AI providers unavailable — template used)*";
    }

    private function fallbackMeetingSummary(string $transcript): string
    {
        $words = str_word_count($transcript);
        return "## Meeting Summary\n\n**Words analyzed**: {$words}\n\n"
            . "### Key Points\n- Meeting covered multiple topics.\n\n"
            . "### Action Items\n1. Follow up on discussion.\n2. Schedule follow-up.\n\n"
            . "*(AI providers unavailable — basic analysis used)*";
    }

    private function fallbackResumeParse(string $text): array
    {
        preg_match('/[\w.+-]+@[\w-]+\.[\w.]+/', $text, $email);
        preg_match('/[\+]?[\d\s\-\(\)]{7,15}/', $text, $phone);
        $firstLine = trim(strtok($text, "\n") ?: '');
        $skills = [];
        foreach (['PHP','Java','Python','JavaScript','React','Angular','Vue','Node.js','SQL','Docker','AWS','Git','Symfony','Laravel','Spring'] as $kw) {
            if (stripos($text, $kw) !== false) $skills[] = $kw;
        }
        return [
            'name' => strlen($firstLine) < 60 ? $firstLine : 'Not detected',
            'email' => $email[0] ?? 'Not found', 'phone' => trim($phone[0] ?? 'Not found'),
            'location' => 'Not detected',
            'summary' => str_word_count($text) . ' words, ' . count($skills) . ' skills found.',
            'skills' => $skills ?: ['No skills detected'],
            'experience' => [['title' => 'AI unavailable', 'company' => 'Paste full resume for better results', 'period' => '', 'description' => '']],
            'education' => [['degree' => 'AI unavailable', 'institution' => '', 'year' => '']],
            'certifications' => [], 'languages' => [],
        ];
    }

    private function fallbackInterview(string $role, string $level, string $action, string $answer, int $qNum): array
    {
        $questions = [
            "Tell me about yourself and why you're interested in the **{$role}** position.",
            "Describe a challenging project you worked on.",
            "How do you handle tight deadlines?",
            "Explain a technical concept related to **{$role}** simply.",
            "Tell me about a disagreement with a teammate.",
            "What's your approach to learning new technologies?",
            "How do you ensure code quality?",
            "Where do you see yourself in 3-5 years?",
        ];
        if ($action === 'start') {
            return ['type' => 'question', 'message' => "Welcome! Mock interview for **{$role}** ({$level}).\n\n**Q1/8:**\n{$questions[0]}", 'question' => 1];
        }
        if ($action === 'end') {
            return ['type' => 'summary', 'message' => "## Interview Summary\n**Role**: {$role} ({$level})\n**Completed**: {$qNum}/8\n\n*(AI unavailable — connect a provider for detailed feedback)*"];
        }
        $nextQ = min($qNum + 1, 8);
        return ['type' => 'feedback', 'message' => ($action === 'skip' ? "Skipped." : "Good answer!") . "\n\n**Q{$nextQ}/8:**\n" . ($questions[$nextQ - 1] ?? end($questions)), 'question' => $nextQ];
    }
}
