<?php

namespace App\Service;

use App\Entity\CloudflareSettings;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Psr\Log\LoggerInterface;
use Exception;

class CloudflareService
{
    private HttpClientInterface $httpClient;
    private LoggerInterface $logger;

    public function __construct(HttpClientInterface $httpClient, LoggerInterface $logger)
    {
        $this->httpClient = $httpClient;
        $this->logger = $logger;
    }

    /**
     * Adds a Custom Hostname (SaaS) to Cloudflare and returns validation records.
     * 
     * @return array [
     *   'id' => string,
     *   'validation_name' => string,
     *   'validation_value' => string,
     *   'status' => string
     * ]
     * @throws Exception If API call fails or missing settings
     */
    public function addCustomHostname(string $domain, CloudflareSettings $settings): array
    {
        if (!$settings->getApiToken() || !$settings->getZoneId()) {
            throw new Exception("Cloudflare API settings are missing.");
        }

        try {
            $response = $this->httpClient->request('POST', 'https://api.cloudflare.com/client/v4/zones/' . $settings->getZoneId() . '/custom_hostnames', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $settings->getApiToken(),
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'hostname' => $domain,
                    'ssl' => [
                        'method' => 'txt',
                        'type' => 'dv',
                        'settings' => [
                            'http2' => 'on',
                            'tls_1_3' => 'on',
                        ]
                    ]
                ]
            ]);

            $data = $response->toArray();
            
            if (!$data['success']) {
                $errorMsg = isset($data['errors'][0]['message']) ? $data['errors'][0]['message'] : 'Unknown error';
                throw new Exception("Cloudflare API Error: " . $errorMsg);
            }

            $result = $data['result'];
            
            // Extract the validation record
            $validationName = null;
            $validationValue = null;
            
            if (isset($result['ssl']['validation_records']) && count($result['ssl']['validation_records']) > 0) {
                $validationRecord = $result['ssl']['validation_records'][0];
                $validationName = $validationRecord['txt_name'];
                $validationValue = $validationRecord['txt_value'];
            }

            return [
                'id' => $result['id'],
                'validation_name' => $validationName,
                'validation_value' => $validationValue,
                'status' => $result['status'] ?? 'pending'
            ];

        } catch (TransportExceptionInterface $e) {
            $this->logger->error("Cloudflare Add Custom Hostname HTTP Error: " . $e->getMessage());
            throw new Exception("Failed to communicate with Cloudflare.");
        } catch (\Exception $e) {
            $this->logger->error("Cloudflare Add Custom Hostname Error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Deletes a Custom Hostname from Cloudflare
     */
    public function deleteCustomHostname(string $hostnameId, CloudflareSettings $settings): bool
    {
        if (!$settings->getApiToken() || !$settings->getZoneId()) {
            return false;
        }

        try {
            $response = $this->httpClient->request('DELETE', 'https://api.cloudflare.com/client/v4/zones/' . $settings->getZoneId() . '/custom_hostnames/' . $hostnameId, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $settings->getApiToken(),
                    'Content-Type' => 'application/json',
                ]
            ]);

            $data = $response->toArray();
            return $data['success'] ?? false;
        } catch (\Exception $e) {
            $this->logger->error("Cloudflare Delete Custom Hostname Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get the status of a custom hostname
     */
    public function getHostnameStatus(string $hostnameId, CloudflareSettings $settings): ?array
    {
        if (!$settings->getApiToken() || !$settings->getZoneId()) {
            return null;
        }

        try {
            $response = $this->httpClient->request('GET', 'https://api.cloudflare.com/client/v4/zones/' . $settings->getZoneId() . '/custom_hostnames/' . $hostnameId, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $settings->getApiToken(),
                    'Content-Type' => 'application/json',
                ]
            ]);

            $data = $response->toArray();
            if ($data['success']) {
                return $data['result'];
            }
            return null;
        } catch (\Exception $e) {
            $this->logger->error("Cloudflare Get Custom Hostname Status Error: " . $e->getMessage());
            return null;
        }
    }
}
