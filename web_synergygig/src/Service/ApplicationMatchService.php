<?php

namespace App\Service;

use App\Entity\Offer;
use Doctrine\DBAL\Connection;

class ApplicationMatchService
{
    public function __construct(private Connection $connection)
    {
    }

    public function matchOfferToUser(Offer $offer, int $userId): array
    {
        $userSkills = $this->getUserSkills($userId);
        $expectedSkills = $this->extractExpectedSkills($offer);

        if (empty($expectedSkills)) {
            return [
                'score' => 50,
                'label' => 'Partial analysis',
                'matched_skills' => [],
                'missing_skills' => [],
                'user_skills' => array_keys($userSkills),
                'expected_skills' => [],
                'summary' => 'No target skills could be detected from this offer. Match remains partial.',
                'strengths' => ['User profile loaded'],
                'warnings' => ['Expected skills not automatically detected'],
            ];
        }

        $matched = [];
        $missing = [];
        $weightedScore = 0;
        $maxScore = 0;

        foreach ($expectedSkills as $skill) {
            $maxScore += 100;
            $normalizedSkill = $this->normalize($skill);

            // Check direct match or partial match in user skills
            $found = false;
            foreach ($userSkills as $uSkill => $uLevel) {
                if ($normalizedSkill === $uSkill || str_contains($uSkill, $normalizedSkill) || str_contains($normalizedSkill, $uSkill)) {
                    $skillWeight = match ($uLevel) {
                        'ADVANCED', 'EXPERT' => 100,
                        'INTERMEDIATE' => 75,
                        'BEGINNER' => 55,
                        default => 50,
                    };
                    $weightedScore += $skillWeight;
                    $matched[] = ['name' => $skill, 'level' => $uLevel];
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $missing[] = $skill;
            }
        }

        // $maxScore is always > 0 here because we return early for empty $expectedSkills
        $score = (int) round(($weightedScore / $maxScore) * 100);

        $label = match (true) {
            $score >= 85 => 'Excellent match',
            $score >= 70 => 'Good match',
            $score >= 50 => 'Average match',
            default => 'Low compatibility',
        };

        $strengths = [];
        $warnings = [];

        if (!empty($matched)) {
            $strengths[] = count($matched) . ' matching skill(s) found';
        }
        if (count($missing) === 0) {
            $strengths[] = 'No key skills missing';
        } else {
            $warnings[] = count($missing) . ' key skill(s) appear to be missing';
        }
        if ($score >= 70) {
            $strengths[] = 'Profile appears consistent with offer requirements';
        } else {
            $warnings[] = 'Profile may need strengthening for better compatibility';
        }

        return [
            'score' => $score,
            'label' => $label,
            'matched_skills' => $matched,
            'missing_skills' => $missing,
            'user_skills' => array_keys($userSkills),
            'expected_skills' => $expectedSkills,
            'summary' => $this->buildSummary($score, $matched, $missing),
            'strengths' => $strengths,
            'warnings' => $warnings,
        ];
    }

    private function getUserSkills(int $userId): array
    {
        // Try the user_skills join table if it exists
        try {
            $sql = <<<SQL
                SELECT s.name, us.level
                FROM user_skills us
                INNER JOIN skills s ON s.id = us.skill_id
                WHERE us.user_id = :userId
            SQL;

            $rows = $this->connection->fetchAllAssociative($sql, ['userId' => $userId]);

            $skills = [];
            foreach ($rows as $row) {
                $skills[$this->normalize($row['name'])] = $row['level'] ?? 'INTERMEDIATE';
            }

            return $skills;
        } catch (\Throwable) {
            // Table doesn't exist yet — return empty
            return [];
        }
    }

    private function extractExpectedSkills(Offer $offer): array
    {
        // First, check the required_skills field directly
        $requiredSkills = $offer->getRequiredSkills();
        if ($requiredSkills) {
            $parts = preg_split('/[,;|]+/', $requiredSkills);
            $list = array_map('trim', $parts === false ? [] : $parts);
            $list = array_filter($list, fn($s) => strlen($s) > 1);
            if (!empty($list)) {
                return array_values($list);
            }
        }

        // Fallback: keyword-based detection from title + description
        $text = mb_strtolower(trim(($offer->getTitle() ?? '') . ' ' . ($offer->getDescription() ?? '')));

        $map = [
            'Java' => ['java', 'jdbc', 'spring boot', 'spring'],
            'PHP' => ['php', 'symfony', 'laravel'],
            'MySQL' => ['mysql', 'mariadb', 'sql', 'database'],
            'API REST' => ['api', 'rest', 'restful'],
            'UI/UX' => ['ui', 'ux', 'figma', 'design'],
            'JavaScript' => ['javascript', 'js', 'typescript', 'ts'],
            'React' => ['react', 'next.js', 'nextjs'],
            'Python' => ['python', 'django', 'flask'],
            'Docker' => ['docker', 'container', 'kubernetes'],
            'Git' => ['git', 'github', 'gitlab'],
            'Web Development' => ['web', 'website', 'frontend', 'backend'],
            'CSS' => ['css', 'bootstrap', 'tailwind', 'sass'],
            'Node.js' => ['node', 'nodejs', 'express'],
        ];

        $detected = [];
        foreach ($map as $skillName => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($text, $keyword)) {
                    $detected[] = $skillName;
                    break;
                }
            }
        }

        return array_values(array_unique($detected));
    }

    private function buildSummary(int $score, array $matched, array $missing): string
    {
        $matchedCount = count($matched);
        $missingCount = count($missing);

        return match (true) {
            $score >= 85 => "User profile is strongly aligned with this offer. {$matchedCount} key skill(s) match directly.",
            $score >= 70 => "Good compatibility with this offer. {$matchedCount} skill(s) match, with some areas to strengthen.",
            $score >= 50 => "Average compatibility. Profile covers some needs, but {$missingCount} important skill(s) appear missing.",
            default => "Current compatibility appears low. Several key skills are not yet covered by the user profile.",
        };
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
