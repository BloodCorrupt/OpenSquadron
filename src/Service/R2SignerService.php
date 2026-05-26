<?php

namespace App\Service;

class R2SignerService
{
    /**
     * Generate a presigned PUT URL for uploading a file directly to R2 / S3.
     */
    public function generatePresignedPutUrl(
        string $accountId,
        string $accessKeyId,
        string $secretAccessKey,
        string $bucketName,
        string $objectKey,
        string $contentType,
        int $expiresInSeconds = 3600
    ): string {
        // Cloudflare R2 S3 API Endpoint host
        $host = "{$bucketName}.{$accountId}.r2.cloudflarestorage.com";
        $region = 'auto';
        $service = 's3';

        $now = new \DateTime('UTC');
        $amzDate = $now->format('Ymd\THis\Z');
        $date = $now->format('Ymd');

        $credentialScope = "{$date}/{$region}/{$service}/aws4_request";

        // Query parameters for presigning
        $queryParams = [
            'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => "{$accessKeyId}/{$credentialScope}",
            'X-Amz-Date' => $amzDate,
            'X-Amz-Expires' => (string)$expiresInSeconds,
            'X-Amz-SignedHeaders' => 'host',
        ];

        // R2 requires query params to be alphabetically sorted
        ksort($queryParams);

        $canonicalQueryParts = [];
        foreach ($queryParams as $key => $value) {
            $canonicalQueryParts[] = rawurlencode($key) . '=' . rawurlencode($value);
        }
        $canonicalQueryString = implode('&', $canonicalQueryParts);

        $canonicalUri = '/' . ltrim($objectKey, '/');

        $canonicalHeaders = "host:{$host}\n";
        $signedHeaders = 'host';
        $hashedPayload = 'UNSIGNED-PAYLOAD';

        $canonicalRequest = "PUT\n"
            . $canonicalUri . "\n"
            . $canonicalQueryString . "\n"
            . $canonicalHeaders . "\n"
            . $signedHeaders . "\n"
            . $hashedPayload;

        $stringToSign = "AWS4-HMAC-SHA256\n"
            . $amzDate . "\n"
            . $credentialScope . "\n"
            . hash('sha256', $canonicalRequest);

        $signingKey = $this->getSignatureKey($secretAccessKey, $date, $region, $service);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        return "https://{$host}{$canonicalUri}?{$canonicalQueryString}&X-Amz-Signature={$signature}";
    }

    /**
     * Generate a presigned URL for GET/PUT/DELETE bucket lifecycle configuration.
     */
    public function generatePresignedLifecycleUrl(
        string $accountId,
        string $accessKeyId,
        string $secretAccessKey,
        string $bucketName,
        string $method = 'PUT',
        int $expiresInSeconds = 3600
    ): string {
        // Cloudflare R2 S3 API Endpoint host
        $host = "{$bucketName}.{$accountId}.r2.cloudflarestorage.com";
        $region = 'auto';
        $service = 's3';

        $now = new \DateTime('UTC');
        $amzDate = $now->format('Ymd\THis\Z');
        $date = $now->format('Ymd');

        $credentialScope = "{$date}/{$region}/{$service}/aws4_request";

        // Query parameters for presigning - note that 'lifecycle' must be included
        $queryParams = [
            'lifecycle' => '',
            'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => "{$accessKeyId}/{$credentialScope}",
            'X-Amz-Date' => $amzDate,
            'X-Amz-Expires' => (string)$expiresInSeconds,
            'X-Amz-SignedHeaders' => 'host',
        ];

        // R2 requires query params to be alphabetically sorted
        ksort($queryParams);

        $canonicalQueryParts = [];
        foreach ($queryParams as $key => $value) {
            if ($value === '') {
                $canonicalQueryParts[] = rawurlencode($key) . '=';
            } else {
                $canonicalQueryParts[] = rawurlencode($key) . '=' . rawurlencode($value);
            }
        }
        $canonicalQueryString = implode('&', $canonicalQueryParts);

        $canonicalUri = '/';

        $canonicalHeaders = "host:{$host}\n";
        $signedHeaders = 'host';
        $hashedPayload = 'UNSIGNED-PAYLOAD';

        $canonicalRequest = "{$method}\n"
            . $canonicalUri . "\n"
            . $canonicalQueryString . "\n"
            . $canonicalHeaders . "\n"
            . $signedHeaders . "\n"
            . $hashedPayload;

        $stringToSign = "AWS4-HMAC-SHA256\n"
            . $amzDate . "\n"
            . $credentialScope . "\n"
            . hash('sha256', $canonicalRequest);

        $signingKey = $this->getSignatureKey($secretAccessKey, $date, $region, $service);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        return "https://{$host}{$canonicalUri}?{$canonicalQueryString}&X-Amz-Signature={$signature}";
    }

    private function getSignatureKey(string $key, string $dateStamp, string $regionName, string $serviceName): string
    {
        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $key, true);
        $kRegion = hash_hmac('sha256', $regionName, $kDate, true);
        $kService = hash_hmac('sha256', $serviceName, $kRegion, true);
        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }
}
