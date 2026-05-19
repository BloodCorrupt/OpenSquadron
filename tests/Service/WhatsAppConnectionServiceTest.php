<?php

namespace App\Tests\Service;

use App\Service\WhatsAppConnectionService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class WhatsAppConnectionServiceTest extends TestCase
{
    private WhatsAppConnectionService $service;

    protected function setUp(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $httpClient = $this->createMock(HttpClientInterface::class);
        $router = $this->createMock(RouterInterface::class);

        $router->method('generate')->willReturn('https://example.com/webhook/whatsapp');

        // Secret key
        $appSecret = 'dummy_app_secret_for_testing';

        $this->service = new WhatsAppConnectionService(
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
            $this->assertStringEndsWith('/webhook/whatsapp', $url);
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

    public function testMaskTokenOutputLengthAndMaskCharacters(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $input = bin2hex(random_bytes(random_int(1, 50)));
            $length = strlen($input);
            $masked = $this->service->maskToken($input);

            $this->assertSame($length, strlen($masked));
            if ($length <= 4) {
                $this->assertSame(str_repeat('*', $length), $masked);
            } else {
                $this->assertStringEndsWith(substr($input, -4), $masked);
                $this->assertSame(str_repeat('*', $length - 4), substr($masked, 0, $length - 4));
            }
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
