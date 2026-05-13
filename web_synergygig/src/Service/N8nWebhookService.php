<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class N8nWebhookService
{
    private string $baseUrl;
    private string $webhookSecret;
    private HttpClientInterface $client;
    private LoggerInterface $logger;

    public function __construct(
        HttpClientInterface $client,
        LoggerInterface $logger,
        string $n8nBaseUrl = 'http://localhost:5678',
        string $webhookSecret = 'synergygig-webhook-secret'
    ) {
        $this->client = $client;
        $this->logger = $logger;
        $this->baseUrl = rtrim($n8nBaseUrl, '/');
        $this->webhookSecret = $webhookSecret;
    }

    /**
     * Fire a webhook with HMAC-SHA256 signature and automatic retry (up to 3 attempts).
     * Used for critical business events that must be auditable.
     */
    private function fireWithRetry(string $path, array $data, int $maxRetries = 3): ?array
    {
        $correlationId = $data['correlation_id'] ?? uniqid('sg_', true);
        $body = json_encode($data);
        if ($body === false) {
            throw new \RuntimeException('Unable to encode webhook payload.');
        }
        $signature = 'sha256=' . hash_hmac('sha256', $body, $this->webhookSecret);

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $response = $this->client->request('POST', $this->baseUrl . $path, [
                    'body' => $body,
                    'headers' => [
                        'Content-Type'           => 'application/json',
                        'X-SynergyGig-Signature' => $signature,
                        'X-Correlation-ID'       => $correlationId,
                        'X-Attempt'              => (string) $attempt,
                    ],
                    'timeout' => 10,
                ]);

                $statusCode = $response->getStatusCode();
                if ($statusCode >= 200 && $statusCode < 300) {
                    $this->logger->info('n8n webhook delivered', [
                        'path' => $path, 'attempt' => $attempt, 'correlation_id' => $correlationId,
                    ]);
                    return $response->toArray(false);
                }

                $this->logger->warning('n8n webhook non-2xx', [
                    'path' => $path, 'status' => $statusCode, 'attempt' => $attempt,
                ]);
            } catch (\Exception $e) {
                $this->logger->warning('n8n webhook attempt failed', [
                    'path' => $path, 'attempt' => $attempt, 'error' => $e->getMessage(),
                ]);
            }

            // Exponential backoff: 1s, 2s before retries 2 and 3
            if ($attempt < $maxRetries) {
                usleep($attempt * 1_000_000);
            }
        }

        $this->logger->error('n8n webhook failed after all retries', [
            'path' => $path, 'correlation_id' => $correlationId,
        ]);
        return null;
    }

    /**
     * Fire a webhook to n8n.
     *
     * @param string $path Webhook path (e.g. /webhook/training-enroll)
     * @param array  $data Payload to send
     * @return array|null Response data or null on failure
     */
    public function fire(string $path, array $data): ?array
    {
        $url = $this->baseUrl . $path;

        try {
            $response = $this->client->request('POST', $url, [
                'json' => $data,
                'timeout' => 10,
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode >= 200 && $statusCode < 300) {
                $this->logger->info('n8n webhook fired successfully', [
                    'path' => $path,
                    'status' => $statusCode,
                ]);
                return $response->toArray(false);
            }

            $this->logger->warning('n8n webhook returned non-2xx', [
                'path' => $path,
                'status' => $statusCode,
            ]);
            return null;
        } catch (\Exception $e) {
            // Don't let webhook failures break the app flow
            $this->logger->error('n8n webhook failed', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    // ── Convenience methods ──

    public function trainingEnrolled(int $userId, string $userName, int $courseId, string $courseTitle): ?array
    {
        return $this->fire('/webhook/training-enroll', [
            'user_id' => $userId,
            'user_name' => $userName,
            'course_id' => $courseId,
            'course_title' => $courseTitle,
            'enrolled_at' => (new \DateTime())->format('Y-m-d H:i:s'),
        ]);
    }

    public function trainingCompleted(int $userId, string $userName, int $courseId, string $courseTitle, float $score): ?array
    {
        return $this->fire('/webhook/training-complete', [
            'user_id' => $userId,
            'user_name' => $userName,
            'course_id' => $courseId,
            'course_title' => $courseTitle,
            'score' => $score,
            'completed_at' => (new \DateTime())->format('Y-m-d H:i:s'),
        ]);
    }

    public function leaveStatusChanged(int $leaveId, string $employeeName, string $type, string $status, string $approverName): ?array
    {
        return $this->fire('/webhook/leave-status', [
            'leave_id' => $leaveId,
            'employee_name' => $employeeName,
            'leave_type' => $type,
            'status' => $status,
            'approver_name' => $approverName,
            'updated_at' => (new \DateTime())->format('Y-m-d H:i:s'),
        ]);
    }

    public function contractSigned(int $contractId, string $title, string $signerName, float $amount): ?array
    {
        return $this->fire('/webhook/contract-signed', [
            'contract_id' => $contractId,
            'title' => $title,
            'signer' => $signerName,
            'amount' => $amount,
            'signed_at' => (new \DateTime())->format('Y-m-d H:i:s'),
        ]);
    }

    public function payrollGenerated(int $count, string $month, float $totalAmount): ?array
    {
        return $this->fire('/webhook/payroll-generated', [
            'count' => $count,
            'month' => $month,
            'total_amount' => $totalAmount,
            'generated_at' => (new \DateTime())->format('Y-m-d H:i:s'),
        ]);
    }

    public function interviewScheduled(int $interviewId, string $candidateName, string $position, string $date): ?array
    {
        return $this->fire('/webhook/interview-scheduled', [
            'interview_id' => $interviewId,
            'candidate_name' => $candidateName,
            'position' => $position,
            'date' => $date,
            'created_at' => (new \DateTime())->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Fired when HR accepts an interview — triggers contract creation flow in n8n.
     * Uses HMAC-signed retry pipeline for auditability.
     */
    public function interviewAccepted(
        int    $interviewId,
        int    $candidateId,
        string $candidateName,
        string $candidateEmail,
        int    $offerId,
        string $offerTitle,
        int    $contractId,
        string $acceptedBy,
        string $contractType = 'GIG'
    ): ?array {
        $correlationId = 'sgig-' . $interviewId . '-' . uniqid();

        return $this->fireWithRetry('/webhook/interview-accepted', [
            'correlation_id'  => $correlationId,
            'trigger'         => 'interview_accepted',
            'platform'        => 'SynergyGig',
            'accepted_at'     => (new \DateTime())->format('c'),
            'interview_id'    => $interviewId,
            'candidate_id'    => $candidateId,
            'candidate_name'  => $candidateName,
            'candidate_email' => $candidateEmail,
            'offer_id'        => $offerId,
            'offer_title'     => $offerTitle,
            'contract_id'     => $contractId,
            'contract_type'   => $contractType,
            'accepted_by'     => $acceptedBy,
            'next_step'       => 'ai_contract_generation',
        ]);
    }
}
