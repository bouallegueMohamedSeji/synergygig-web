<?php

namespace App\Service;

use Psr\Log\LoggerInterface;

class ExternalJobService
{
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Search all sources and return a normalized array of job listings.
     */
    public function search(string $query, string $source = 'all', string $category = ''): array
    {
        $jobs = [];

        $sources = ($source === 'all')
            ? ['remotive', 'arbeitnow', 'remoteok', 'jobicy']
            : [strtolower($source)];

        foreach ($sources as $src) {
            $fetched = match ($src) {
                'remotive'  => $this->fetchRemotive($query, $category),
                'arbeitnow' => $this->fetchArbeitnow($query),
                'remoteok'  => $this->fetchRemoteOK($query),
                'jobicy'    => $this->fetchJobicy($query, $category),
                default     => [],
            };
            $jobs = array_merge($jobs, $fetched);
        }

        // Sort by date descending
        usort($jobs, fn($a, $b) => strtotime($b['date'] ?? '1970-01-01') - strtotime($a['date'] ?? '1970-01-01'));

        return $jobs;
    }

    /**
     * Remotive: https://remotive.com/api/remote-jobs
     */
    private function fetchRemotive(string $query, string $category = ''): array
    {
        $params = ['search' => $query, 'limit' => 20];
        if ($category) {
            $params['category'] = $category;
        }
        $url = 'https://remotive.com/api/remote-jobs?' . http_build_query($params);

        $data = $this->curlGet($url);
        if (!$data || !isset($data['jobs'])) {
            return [];
        }

        $jobs = [];
        foreach ($data['jobs'] as $j) {
            $jobs[] = [
                'source'    => 'Remotive',
                'title'     => $j['title'] ?? '',
                'company'   => $j['company_name'] ?? '',
                'location'  => $j['candidate_required_location'] ?? 'Remote',
                'url'       => $j['url'] ?? '',
                'date'      => $j['publication_date'] ?? '',
                'type'      => $j['job_type'] ?? '',
                'salary'    => $j['salary'] ?? '',
                'category'  => $j['category'] ?? '',
                'tags'      => $j['tags'] ?? [],
                'description' => strip_tags(mb_substr($j['description'] ?? '', 0, 300)),
            ];
        }
        return $jobs;
    }

    /**
     * Arbeitnow: https://www.arbeitnow.com/api/job-board-api
     */
    private function fetchArbeitnow(string $query): array
    {
        $url = 'https://www.arbeitnow.com/api/job-board-api';

        $data = $this->curlGet($url);
        if (!$data || !isset($data['data'])) {
            return [];
        }

        $queryLower = mb_strtolower($query);
        $jobs = [];
        foreach ($data['data'] as $j) {
            // Client-side filter since the API doesn't support search
            $title = mb_strtolower($j['title'] ?? '');
            $company = mb_strtolower($j['company_name'] ?? '');
            $desc = mb_strtolower($j['description'] ?? '');
            $tagsStr = mb_strtolower(implode(' ', $j['tags'] ?? []));

            if (
                str_contains($title, $queryLower) ||
                str_contains($company, $queryLower) ||
                str_contains($desc, $queryLower) ||
                str_contains($tagsStr, $queryLower)
            ) {
                $jobs[] = [
                    'source'    => 'Arbeitnow',
                    'title'     => $j['title'] ?? '',
                    'company'   => $j['company_name'] ?? '',
                    'location'  => $j['location'] ?? 'Remote',
                    'url'       => $j['url'] ?? '',
                    'date'      => $j['created_at'] ? date('Y-m-d', $j['created_at']) : '',
                    'type'      => $j['remote'] ? 'Remote' : 'On-site',
                    'salary'    => '',
                    'category'  => '',
                    'tags'      => $j['tags'] ?? [],
                    'description' => strip_tags(mb_substr($j['description'] ?? '', 0, 300)),
                ];
            }
            if (count($jobs) >= 20) break;
        }
        return $jobs;
    }

    /**
     * RemoteOK: https://remoteok.com/api
     */
    private function fetchRemoteOK(string $query): array
    {
        $url = 'https://remoteok.com/api?tag=' . urlencode($query);

        $data = $this->curlGet($url, ['User-Agent: SynergyGig/1.0']);
        if (!$data || !is_array($data)) {
            return [];
        }

        $jobs = [];
        foreach ($data as $j) {
            // First element is usually metadata, skip
            if (!isset($j['id'])) continue;

            $jobs[] = [
                'source'    => 'RemoteOK',
                'title'     => $j['position'] ?? '',
                'company'   => $j['company'] ?? '',
                'location'  => $j['location'] ?? 'Remote',
                'url'       => $j['url'] ?? '',
                'date'      => $j['date'] ?? '',
                'type'      => 'Remote',
                'salary'    => isset($j['salary_min'], $j['salary_max'])
                    ? '$' . number_format($j['salary_min']) . ' - $' . number_format($j['salary_max'])
                    : '',
                'category'  => '',
                'tags'      => $j['tags'] ?? [],
                'description' => strip_tags(mb_substr($j['description'] ?? '', 0, 300)),
            ];
            if (count($jobs) >= 20) break;
        }
        return $jobs;
    }

    /**
     * Jobicy: https://jobicy.com/api/v2/remote-jobs
     */
    private function fetchJobicy(string $query, string $category = ''): array
    {
        $params = ['count' => 20, 'tag' => $query];
        if ($category) {
            $params['industry'] = $category;
        }
        $url = 'https://jobicy.com/api/v2/remote-jobs?' . http_build_query($params);

        $data = $this->curlGet($url);
        if (!$data || !isset($data['jobs'])) {
            return [];
        }

        $jobs = [];
        foreach ($data['jobs'] as $j) {
            $jobs[] = [
                'source'    => 'Jobicy',
                'title'     => $j['jobTitle'] ?? '',
                'company'   => $j['companyName'] ?? '',
                'location'  => $j['jobGeo'] ?? 'Remote',
                'url'       => $j['url'] ?? '',
                'date'      => $j['pubDate'] ?? '',
                'type'      => $j['jobType'] ?? '',
                'salary'    => isset($j['annualSalaryMin'], $j['annualSalaryMax'])
                    ? '$' . number_format($j['annualSalaryMin']) . ' - $' . number_format($j['annualSalaryMax'])
                    : '',
                'category'  => $j['jobIndustry'][0] ?? '',
                'tags'      => [],
                'description' => strip_tags(mb_substr($j['jobExcerpt'] ?? '', 0, 300)),
            ];
        }
        return $jobs;
    }

    /**
     * Perform a cURL GET request and return decoded JSON.
     */
    private function curlGet(string $url, array $headers = []): ?array
    {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_FOLLOWLOCATION => true,
        ];
        if ($headers) {
            $opts[CURLOPT_HTTPHEADER] = $headers;
        }
        curl_setopt_array($ch, $opts);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            $this->logger->warning('ExternalJob API failed', ['url' => $url, 'httpCode' => $httpCode]);
            return null;
        }

        $data = json_decode((string) $response, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Get available categories for filtering.
     */
    public function getCategories(): array
    {
        return [
            'software-dev'    => 'Software Development',
            'design'          => 'Design',
            'marketing'       => 'Marketing',
            'customer-support'=> 'Customer Support',
            'sales'           => 'Sales',
            'product'         => 'Product',
            'business'        => 'Business',
            'data'            => 'Data',
            'devops'          => 'DevOps / SysAdmin',
            'finance'         => 'Finance / Legal',
            'hr'              => 'Human Resources',
            'qa'              => 'QA',
            'writing'         => 'Writing',
            'all-others'      => 'All Others',
        ];
    }
}
