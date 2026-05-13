<?php

namespace App\Service;

use App\Entity\Offer;

class OfferAiInsightService
{
    public function __construct(private AIService $aiService)
    {
    }

    public function analyzeOffer(Offer $offer): array
    {
        $prompt = $this->buildPrompt($offer);

        $system = 'You are a freelance marketplace analyst. Respond ONLY with valid JSON, no markdown fences.';
        $result = $this->aiService->chat($system, $prompt, 0.4, 512);

        if (!$result) {
            return $this->fallbackInsight($offer);
        }

        // Strip markdown fences if present
        $result = preg_replace('/^```(?:json)?\s*/i', '', trim($result)) ?? trim($result);
        $result = preg_replace('/\s*```$/', '', $result) ?? $result;

        $decoded = json_decode($result, true);

        if (!is_array($decoded)) {
            return [
                ...$this->fallbackInsight($offer),
                'raw_text' => $result,
            ];
        }

        return [
            'category' => $decoded['category'] ?? 'General',
            'experience_level' => $decoded['experience_level'] ?? 'Mid',
            'urgency' => $decoded['urgency'] ?? 'Medium',
            'risk' => $decoded['risk'] ?? 'Moderate',
            'score' => (int) ($decoded['score'] ?? 70),
            'summary' => $decoded['summary'] ?? 'AI analysis unavailable.',
            'strengths' => $decoded['strengths'] ?? [],
            'warnings' => $decoded['warnings'] ?? [],
        ];
    }

    private function buildPrompt(Offer $offer): string
    {
        return <<<PROMPT
Analyze this job offer and return ONLY valid JSON.

Offer title: {$offer->getTitle()}
Offer description: {$offer->getDescription()}
Offer type: {$offer->getOfferType()}
Budget: {$offer->getAmount()} {$offer->getCurrency()}
Required skills: {$offer->getRequiredSkills()}
Location: {$offer->getLocation()}

Expected JSON format:
{
  "category": "Web Development | UI/UX Design | Marketing | Data | DevOps | General",
  "experience_level": "Junior | Mid | Senior",
  "urgency": "Low | Medium | High",
  "risk": "Low | Moderate | High",
  "score": 0-100,
  "summary": "short professional summary",
  "strengths": ["...", "..."],
  "warnings": ["...", "..."]
}
PROMPT;
    }

    private function fallbackInsight(Offer $offer): array
    {
        $score = 65;
        $strengths = [];
        $warnings = [];

        if ($offer->getTitle()) {
            $strengths[] = 'Title provided';
        }
        if ($offer->getDescription() && strlen($offer->getDescription()) > 50) {
            $strengths[] = 'Detailed description';
            $score += 10;
        }
        if ($offer->getAmount() && $offer->getAmount() > 0) {
            $strengths[] = 'Budget specified';
            $score += 5;
        } else {
            $warnings[] = 'No budget specified';
        }
        if ($offer->getRequiredSkills()) {
            $strengths[] = 'Required skills listed';
            $score += 5;
        } else {
            $warnings[] = 'No required skills specified';
        }
        if (!$offer->getDescription() || strlen($offer->getDescription()) < 30) {
            $warnings[] = 'Description too short';
        }

        return [
            'category' => 'General',
            'experience_level' => 'Mid',
            'urgency' => 'Medium',
            'risk' => 'Moderate',
            'score' => min(100, $score),
            'summary' => 'AI analysis temporarily unavailable. Showing estimated insights.',
            'strengths' => $strengths ?: ['Offer detected'],
            'warnings' => $warnings ?: ['AI analysis not confirmed'],
        ];
    }
}
