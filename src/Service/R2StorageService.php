<?php

namespace App\Service;

use App\Entity\R2Settings;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class R2StorageService
{
    public function __construct(
        private R2SignerService $signerService,
        private HttpClientInterface $httpClient
    ) {
    }

    /**
     * Upload binary content to R2 using a presigned PUT URL.
     * Returns the public URL of the uploaded file on success, or null on failure.
     */
    public function uploadContent(
        R2Settings $settings,
        string $content,
        string $objectKey,
        string $mimeType
    ): ?string {
        if (!$settings->getAccountId() || !$settings->getAccessKeyId() || !$settings->getSecretAccessKey() || !$settings->getBucketName()) {
            return null;
        }

        try {
            $uploadUrl = $this->signerService->generatePresignedPutUrl(
                $settings->getAccountId(),
                $settings->getAccessKeyId(),
                $settings->getSecretAccessKey(),
                $settings->getBucketName(),
                $objectKey,
                $mimeType
            );

            $response = $this->httpClient->request('PUT', $uploadUrl, [
                'headers' => [
                    'Content-Type' => $mimeType,
                ],
                'body' => $content,
            ]);

            if ($response->getStatusCode() === 200) {
                // Return the public URL
                $publicBaseUrl = $settings->getPublicUrl();
                if ($publicBaseUrl) {
                    return rtrim($publicBaseUrl, '/') . '/' . ltrim($objectKey, '/');
                } else {
                    // Fallback to r2.dev subdomain if publicUrl is not set
                    return "https://{$settings->getBucketName()}.{$settings->getAccountId()}.r2.cloudflarestorage.com/" . ltrim($objectKey, '/');
                }
            }
        } catch (\Exception $e) {
            // Log or handle exception
        }

        return null;
    }

    /**
     * Update the Cloudflare R2 bucket lifecycle configuration.
     * If $retentionDays is > 0, PUT the lifecycle configuration XML targeting prefix 'whatsapp/'.
     * Otherwise, DELETE the lifecycle configuration.
     */
    public function updateBucketLifecycle(R2Settings $settings, ?int $retentionDays): bool
    {
        if (!$settings->getAccountId() || !$settings->getAccessKeyId() || !$settings->getSecretAccessKey() || !$settings->getBucketName()) {
            return false;
        }

        try {
            if ($retentionDays !== null && $retentionDays > 0) {
                // Generate standard S3 lifecycle XML config targeting only 'whatsapp/' prefix
                $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
                    . '<LifecycleConfiguration xmlns="http://s3.amazonaws.com/doc/2006-03-01/">' . "\n"
                    . '  <Rule>' . "\n"
                    . '    <ID>AutoDeleteInboxMedia</ID>' . "\n"
                    . '    <Prefix>whatsapp/</Prefix>' . "\n"
                    . '    <Status>Enabled</Status>' . "\n"
                    . '    <Expiration>' . "\n"
                    . '      <Days>' . $retentionDays . '</Days>' . "\n"
                    . '    </Expiration>' . "\n"
                    . '  </Rule>' . "\n"
                    . '</LifecycleConfiguration>';

                $url = $this->signerService->generatePresignedLifecycleUrl(
                    $settings->getAccountId(),
                    $settings->getAccessKeyId(),
                    $settings->getSecretAccessKey(),
                    $settings->getBucketName(),
                    'PUT'
                );

                $response = $this->httpClient->request('PUT', $url, [
                    'headers' => [
                        'Content-Type' => 'application/xml',
                    ],
                    'body' => $xml,
                ]);

                $statusCode = $response->getStatusCode();
                return $statusCode === 200 || $statusCode === 204;
            } else {
                // DELETE request to remove lifecycle policies
                $url = $this->signerService->generatePresignedLifecycleUrl(
                    $settings->getAccountId(),
                    $settings->getAccessKeyId(),
                    $settings->getSecretAccessKey(),
                    $settings->getBucketName(),
                    'DELETE'
                );

                $response = $this->httpClient->request('DELETE', $url);

                $statusCode = $response->getStatusCode();
                // 200, 204 are success, and 404 (if policy doesn't exist) is acceptable
                return $statusCode === 200 || $statusCode === 204 || $statusCode === 404;
            }
        } catch (\Exception $e) {
            // Log or handle exception
            return false;
        }
    }
}
