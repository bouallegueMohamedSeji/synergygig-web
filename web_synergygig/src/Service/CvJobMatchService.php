<?php

namespace App\Service;

/**
 * Scores a job listing against the user's CV skills text.
 * Keyword-based, fast, no AI call needed for bulk scoring.
 * Returns 0–100 integer score.
 */
class CvJobMatchService
{
    /**
     * Score a job title + description against the user's CV text.
     * Returns ['score'=>int, 'label'=>string, 'matched'=>string[], 'missing'=>string[]]
     */
    public function score(string $cvText, string $jobTitle, string $jobDescription): array
    {
        if (!$cvText) {
            return ['score' => 0, 'label' => 'No CV', 'matched' => [], 'missing' => []];
        }

        $cv = mb_strtolower($cvText);
        $job = mb_strtolower($jobTitle . ' ' . $jobDescription);

        // Skill keyword map (tech + soft skills)
        $skillMap = [
            'PHP'            => ['php', 'symfony', 'laravel', 'wordpress'],
            'JavaScript'     => ['javascript', 'js', 'typescript', 'ts', 'es6', 'node', 'vue', 'react', 'angular'],
            'Python'         => ['python', 'django', 'flask', 'fastapi', 'pandas'],
            'Java'           => ['java', 'spring', 'spring boot', 'hibernate', 'maven', 'gradle'],
            'SQL'            => ['sql', 'mysql', 'postgresql', 'mariadb', 'oracle', 'database', 'query'],
            'Docker'         => ['docker', 'container', 'kubernetes', 'k8s', 'helm', 'devops'],
            'Git'            => ['git', 'github', 'gitlab', 'bitbucket', 'ci/cd', 'pipeline'],
            'API'            => ['api', 'rest', 'restful', 'graphql', 'openapi', 'swagger'],
            'React'          => ['react', 'next.js', 'nextjs', 'redux', 'jsx'],
            'Vue'            => ['vue', 'nuxt', 'vuex'],
            'CSS'            => ['css', 'sass', 'scss', 'tailwind', 'bootstrap', 'html'],
            'Linux'          => ['linux', 'unix', 'bash', 'shell', 'nginx', 'apache'],
            'Cloud'          => ['aws', 'azure', 'gcp', 'cloud', 's3', 'ec2', 'lambda'],
            'Security'       => ['security', 'cybersecurity', 'owasp', 'firewall', 'penetration', 'siem', 'soc'],
            'Machine Learning'=> ['machine learning', 'ml', 'deep learning', 'tensorflow', 'pytorch', 'nlp', 'ai'],
            'Project Mgmt'   => ['agile', 'scrum', 'kanban', 'jira', 'trello', 'project management'],
            'Communication'  => ['communication', 'teamwork', 'presentation', 'leadership', 'collaboration'],
            'Testing'        => ['testing', 'unit test', 'phpunit', 'jest', 'selenium', 'qa', 'tdd'],
            'Monitoring'     => ['monitoring', 'grafana', 'prometheus', 'datadog', 'elk', 'kibana', 'logging'],
            'C#/.NET'        => ['c#', '.net', 'asp.net', 'dotnet', 'blazor'],
            'Go'             => [' go ', 'golang'],
            'Rust'           => ['rust', 'cargo'],
            'Ruby'           => ['ruby', 'rails', 'rspec'],
            'Mobile'         => ['android', 'ios', 'swift', 'kotlin', 'flutter', 'react native'],
            'Blockchain'     => ['blockchain', 'solidity', 'ethereum', 'web3'],
        ];

        $matched = [];
        $missing = [];
        $totalJobSkills = 0;
        $matchedScore = 0;

        foreach ($skillMap as $label => $keywords) {
            // Check if this skill is required by the job
            $jobRequires = false;
            foreach ($keywords as $kw) {
                if (str_contains($job, $kw)) {
                    $jobRequires = true;
                    break;
                }
            }
            if (!$jobRequires) continue;

            $totalJobSkills++;

            // Check if user's CV has this skill
            $userHas = false;
            foreach ($keywords as $kw) {
                if (str_contains($cv, $kw)) {
                    $userHas = true;
                    break;
                }
            }

            if ($userHas) {
                $matched[] = $label;
                $matchedScore += 100;
            } else {
                $missing[] = $label;
            }
        }

        if ($totalJobSkills === 0) {
            // No detectable skill keywords — do a word overlap score
            $score = $this->wordOverlapScore($cv, $job);
            return [
                'score'   => $score,
                'label'   => $this->label($score),
                'matched' => [],
                'missing' => [],
            ];
        }

        $score = (int) round($matchedScore / ($totalJobSkills * 100) * 100);

        return [
            'score'   => $score,
            'label'   => $this->label($score),
            'matched' => $matched,
            'missing' => $missing,
        ];
    }

    private function wordOverlapScore(string $cv, string $job): int
    {
        $parts = preg_split('/\W+/', $job);
        $jobWords = array_unique($parts === false ? [] : $parts);
        $jobWords = array_filter($jobWords, fn($w) => strlen($w) > 3);
        if (empty($jobWords)) return 0;

        $cvLower = $cv;
        $hits = 0;
        foreach ($jobWords as $word) {
            if (str_contains($cvLower, $word)) $hits++;
        }
        return (int) min(100, round($hits / count($jobWords) * 100));
    }

    private function label(int $score): string
    {
        return match(true) {
            $score >= 85 => 'Excellent match',
            $score >= 70 => 'Good match',
            $score >= 50 => 'Average match',
            $score >= 30 => 'Low match',
            default      => 'Poor match',
        };
    }
}
