<?php

namespace App\Service;

use Psr\Log\LoggerInterface;

class HunterService
{
    private LoggerInterface $logger;
    private string $apiKey;

    public function __construct(LoggerInterface $logger, string $hunterApiKey = '')
    {
        $this->logger = $logger;
        $this->apiKey = $hunterApiKey ?: ($_ENV['HUNTER_API_KEY'] ?? '');
    }

    /**
     * Verify an email address via Hunter.io API.
     * Returns verification result array or null on failure.
     */
    public function verifyEmail(string $email): ?array
    {
        if ($this->apiKey === '') {
            $this->logger->warning('Hunter.io API key not configured.');
            return null;
        }

        $url = sprintf(
            'https://api.hunter.io/v2/email-verifier?email=%s&api_key=%s',
            urlencode($email),
            urlencode($this->apiKey)
        );

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            $this->logger->warning('Hunter.io API failed', ['email' => $email, 'httpCode' => $httpCode]);
            return null;
        }

        $data = json_decode((string) $response, true);
        if (!isset($data['data'])) {
            return null;
        }

        $d = $data['data'];
        return [
            'email' => $d['email'] ?? $email,
            'result' => $d['result'] ?? 'unknown',        // deliverable, undeliverable, risky, unknown
            'score' => $d['score'] ?? 0,                   // 0-100
            'status' => $d['status'] ?? 'unknown',
            'disposable' => $d['disposable'] ?? false,
            'webmail' => $d['webmail'] ?? false,
            'mx_records' => $d['mx_records'] ?? false,
            'smtp_server' => $d['smtp_server'] ?? false,
            'smtp_check' => $d['smtp_check'] ?? false,
            'accept_all' => $d['accept_all'] ?? false,
            'block' => $d['block'] ?? false,
            'sources' => $d['sources'] ?? [],
        ];
    }
}
