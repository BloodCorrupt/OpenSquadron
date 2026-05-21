<?php

namespace App\Tests\Service;

use App\Service\FacebookService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class FacebookServiceTest extends TestCase
{
    private FacebookService $service;

    protected function setUp(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $httpClient = $this->createMock(HttpClientInterface::class);
        $router = $this->createMock(RouterInterface::class);

        $router->method('generate')->willReturn('https://example.com/webhook/facebook');

        // Secret key
        $appSecret = 'dummy_app_secret_for_testing';

        $this->service = new FacebookService(
            $entityManager,
            $httpClient,
            $router,
            $appSecret
        );
    }

    public function testGenerateVerifyTokenAlwaysProduces64CharHexString(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $token = $this->service->generateVerifyToken();
            $this->assertSame(64, strlen($token));
            $this->assertTrue(ctype_xdigit($token));
        }
    }

    public function testBuildWebhookUrlEndsWithExpectedPath(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $url = $this->service->buildWebhookUrl();
            $this->assertStringEndsWith('/webhook/facebook', $url);
            $this->assertStringStartsWith('https://', $url);
        }
    }

    public function testEncryptThenDecryptRoundTripReturnsOriginalToken(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $plainText = bin2hex(random_bytes(random_int(1, 100)));
            $encrypted = $this->service->encryptToken($plainText);
            $decrypted = $this->service->decryptToken($encrypted);
            $this->assertSame($plainText, $decrypted);
        }
    }

    public function testEncryptTokenOutputDiffersFromPlainText(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $plainText = bin2hex(random_bytes(random_int(1, 100)));
            $encrypted = $this->service->encryptToken($plainText);
            $this->assertNotSame($plainText, $encrypted);
        }
    }
}
