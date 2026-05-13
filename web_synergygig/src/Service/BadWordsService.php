<?php

namespace App\Service;

class BadWordsService
{
    // Leetspeak normalization map (same as Java)
    private const LEET_MAP = [
        '@' => 'a', '4' => 'a',
        '0' => 'o',
        '1' => 'i', '!' => 'i',
        '$' => 's', '5' => 's',
        '3' => 'e', '7' => 't',
    ];

    // Local bad words list (same as Java BadWordsService)
    private const BAD_WORDS = [
        // Severe English
        'fuck', 'fucker', 'fucking', 'shit', 'shitty', 'bullshit',
        'ass', 'asshole', 'bitch', 'bitchy', 'damn', 'goddamn',
        'bastard', 'dick', 'dickhead', 'cock', 'cocksucker', 'cunt',
        'piss', 'pissed', 'whore', 'slut', 'wanker', 'twat', 'prick',
        // Moderate
        'retard', 'idiot', 'moron', 'loser',
        'nigger', 'nigga', 'fag', 'dyke', 'spic', 'kike', 'chink',
        'wetback', 'cracker', 'nazi',
        // Harmful
        'kill', 'murder', 'suicide', 'terrorist', 'terrorism',
        'rape', 'molest', 'pervert',
        // French
        'merde', 'putain', 'connard', 'connasse', 'enculer', 'salope',
        'bordel', 'foutre', 'nique', 'batard', 'pd', 'encule',
        'ta gueule', 'ferme ta gueule', 'ntm', 'fdp', 'tg',
        // Arabic
        'كلب', 'حمار', 'خرا', 'عرص', 'شرموطة', 'زبي', 'نيك',
        'كس', 'طيز', 'منيك', 'ابن الشرموطة',
        // Spanish
        'puta', 'cabron', 'mierda', 'pendejo', 'chingar', 'verga',
        'coño', 'joder', 'gilipollas', 'marica', 'hijo de puta',
        // German
        'scheiße', 'scheisse', 'arschloch', 'hurensohn', 'fotze', 'wichser', 'miststück',
        // Tunisian/Darija
        'zebi', 'zeb', 'kahba', 'kuss', 'nayek', 'manyek', 'ta7an', 'zamel',
        'manyouk', 'kol khara', 'nik', 'nikk', 'barra', 'ya wahch',
        // Self-harm phrases
        'kys', 'kill yourself', 'kill your self', 'go die', 'neck yourself',
    ];

    /**
     * Check text for bad words. Returns ['hasBadWords' => bool, 'censoredContent' => string, 'badWordsCount' => int, 'source' => string]
     */
    public static function check(string $text): array
    {
        if (trim($text) === '') {
            return ['hasBadWords' => false, 'censoredContent' => $text, 'badWordsCount' => 0, 'source' => 'empty'];
        }

        // Try API first
        $apiResult = self::checkApi($text);
        if ($apiResult !== null) {
            return $apiResult;
        }

        // Fallback: local check with leetspeak normalization
        return self::checkLocal($text);
    }

    /**
     * Check via APILayer Bad Words API
     */
    private static function checkApi(string $text): ?array
    {
        $apiKey = $_ENV['BADWORDS_API_KEY'] ?? null;
        if (!$apiKey || $apiKey === 'your_badwords_key') {
            return null;
        }

        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: text/plain\r\napikey: " . $apiKey . "\r\n",
                'content' => $text,
                'timeout' => 5,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents('https://api.apilayer.com/bad_words?censor_character=*', false, $ctx);
        if (!$response) {
            return null;
        }

        $data = json_decode($response, true);
        if (!isset($data['bad_words_total'])) {
            return null;
        }

        return [
            'hasBadWords' => $data['bad_words_total'] > 0,
            'censoredContent' => $data['censored_content'] ?? $text,
            'badWordsCount' => (int)$data['bad_words_total'],
            'source' => 'api',
        ];
    }

    /**
     * Local check with leetspeak normalization (Java pattern)
     */
    private static function checkLocal(string $text): array
    {
        $normalized = self::normalizeLeetspeak(mb_strtolower($text));
        $censored = $text;
        $count = 0;

        foreach (self::BAD_WORDS as $word) {
            $wordLower = mb_strtolower($word);
            // Check both original lowercase and leetspeak-normalized text
            $pattern = '/\b' . preg_quote($wordLower, '/') . '\b/iu';

            if (preg_match($pattern, mb_strtolower($text)) || preg_match($pattern, $normalized)) {
                $count++;
                // Censor in original text (case-insensitive)
                $stars = str_repeat('*', mb_strlen($word));
                $censored = preg_replace('/\b' . preg_quote($word, '/') . '\b/iu', $stars, $censored) ?? $censored;
            }
        }

        // Also check for multi-word phrases without word boundaries
        $phrases = ['kill yourself', 'kill your self', 'go die', 'neck yourself',
                     'ta gueule', 'ferme ta gueule', 'hijo de puta', 'ابن الشرموطة', 'kol khara'];
        foreach ($phrases as $phrase) {
            if (mb_stripos(mb_strtolower($text), mb_strtolower($phrase)) !== false ||
                mb_stripos($normalized, mb_strtolower($phrase)) !== false) {
                if (!preg_match('/\b' . preg_quote(mb_strtolower($phrase), '/') . '\b/iu', mb_strtolower($text))) {
                    $count++;
                }
                $stars = str_repeat('*', mb_strlen($phrase));
                $censored = preg_replace('/' . preg_quote($phrase, '/') . '/iu', $stars, $censored) ?? $censored;
            }
        }

        return [
            'hasBadWords' => $count > 0,
            'censoredContent' => $censored,
            'badWordsCount' => $count,
            'source' => 'local',
        ];
    }

    /**
     * Normalize leetspeak characters
     */
    private static function normalizeLeetspeak(string $text): string
    {
        $result = '';
        $len = mb_strlen($text);
        for ($i = 0; $i < $len; $i++) {
            $ch = mb_substr($text, $i, 1);
            $result .= self::LEET_MAP[$ch] ?? $ch;
        }
        return $result;
    }
}
