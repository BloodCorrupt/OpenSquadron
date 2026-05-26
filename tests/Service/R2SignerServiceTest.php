<?php

namespace App\Tests\Service;

use App\Service\R2SignerService;
use PHPUnit\Framework\TestCase;

class R2SignerServiceTest extends TestCase
{
    private R2SignerService $signerService;

    protected function setUp(): void
    {
        $this->signerService = new R2SignerService();
    }

    public function testGeneratePresignedLifecycleUrlContainsLifecycleQueryString(): void
    {
        $accountId = 'test-account-id';
        $accessKeyId = 'test-access-key-id';
        $secretAccessKey = 'test-secret-access-key';
        $bucketName = 'test-bucket';

        $url = $this->signerService->generatePresignedLifecycleUrl(
            $accountId,
            $accessKeyId,
            $secretAccessKey,
            $bucketName,
            'PUT',
            3600
        );

        $this->assertStringContainsString('https://test-bucket.test-account-id.r2.cloudflarestorage.com/?', $url);
        $this->assertStringContainsString('lifecycle=', $url);
        $this->assertStringContainsString('X-Amz-Algorithm=AWS4-HMAC-SHA256', $url);
        $this->assertStringContainsString('X-Amz-SignedHeaders=host', $url);
        $this->assertStringContainsString('X-Amz-Signature=', $url);

        // Parse query string and check order
        $parsed = parse_url($url);
        $query = $parsed['query'] ?? '';
        
        // Split and verify alphabetical order of keys
        $parts = explode('&', $query);
        $keys = [];
        foreach ($parts as $part) {
            $subparts = explode('=', $part, 2);
            if ($subparts[0] !== 'X-Amz-Signature') {
                $keys[] = $subparts[0];
            }
        }

        $sortedKeys = $keys;
        sort($sortedKeys);
        $this->assertSame($sortedKeys, $keys, "Query parameters must be alphabetically sorted");
    }

    public function testGeneratePresignedLifecycleUrlSupportsDeleteMethod(): void
    {
        $accountId = 'test-account-id';
        $accessKeyId = 'test-access-key-id';
        $secretAccessKey = 'test-secret-access-key';
        $bucketName = 'test-bucket';

        $url = $this->signerService->generatePresignedLifecycleUrl(
            $accountId,
            $accessKeyId,
            $secretAccessKey,
            $bucketName,
            'DELETE',
            3600
        );

        $this->assertStringContainsString('lifecycle=', $url);
        $this->assertStringContainsString('X-Amz-Signature=', $url);
    }
}
