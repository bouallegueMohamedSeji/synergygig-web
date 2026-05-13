<?php

namespace App\Service;

use Psr\Log\LoggerInterface;

class ExchangeRateService
{
    private LoggerInterface $logger;
    private string $apiKey;
    private array $rateCache = [];

    public function __construct(LoggerInterface $logger, string $exchangeRateApiKey = '')
    {
        $this->logger = $logger;
        $this->apiKey = $exchangeRateApiKey ?: ($_ENV['EXCHANGE_RATE_API_KEY'] ?? '');
    }

    /**
     * Get exchange rates for a base currency.
     * Returns ['rates' => [...], 'base' => 'USD', 'updated' => '...'] or null on failure.
     */
    public function getRates(string $baseCurrency = 'USD'): ?array
    {
        $base = strtoupper(trim($baseCurrency));

        if (isset($this->rateCache[$base])) {
            return $this->rateCache[$base];
        }

        // Use free open.er-api.com when no paid key is configured (same JSON format)
        if ($this->apiKey) {
            $url = sprintf('https://v6.exchangerate-api.com/v6/%s/latest/%s', $this->apiKey, $base);
        } else {
            $url = sprintf('https://open.er-api.com/v6/latest/%s', $base);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            $this->logger->warning('ExchangeRate-API failed', ['base' => $base, 'httpCode' => $httpCode]);
            return null;
        }

        $data = json_decode((string) $response, true);
        if (($data['result'] ?? '') !== 'success') {
            $this->logger->warning('ExchangeRate-API error', ['response' => $data]);
            return null;
        }

        $result = [
            'base' => $base,
            'rates' => $data['conversion_rates'] ?? $data['rates'] ?? [],
            'updated' => $data['time_last_update_utc'] ?? ($data['time_last_update_unix'] ?? ''),
        ];

        $this->rateCache[$base] = $result;
        return $result;
    }

    /**
     * Convert an amount from one currency to another.
     */
    public function convert(float $amount, string $from, string $to): ?float
    {
        $rates = $this->getRates($from);
        if (!$rates || !isset($rates['rates'][$to])) {
            return null;
        }
        return round($amount * $rates['rates'][$to], 2);
    }

    /**
     * Get common currencies for the converter dropdown.
     */
    public function getCommonCurrencies(): array
    {
        return [
            'TND' => 'Tunisian Dinar',
            'USD' => 'US Dollar',
            'EUR' => 'Euro',
            'GBP' => 'British Pound',
            'CAD' => 'Canadian Dollar',
            'AED' => 'UAE Dirham',
            'SAR' => 'Saudi Riyal',
            'CHF' => 'Swiss Franc',
            'JPY' => 'Japanese Yen',
            'INR' => 'Indian Rupee',
            'MAD' => 'Moroccan Dirham',
            'EGP' => 'Egyptian Pound',
            'DZD' => 'Algerian Dinar',
        ];
    }
}
