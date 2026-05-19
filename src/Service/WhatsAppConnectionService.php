<?php

namespace App\Service;

use App\Entity\WhatsAppConnection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class WhatsAppConnectionService
{
    private const CIPHER_ALGO = 'aes-256-gcm';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private HttpClientInterface $httpClient,
        private RouterInterface $router,
        #[Autowire(env: 'APP_SECRET')]
        private string $appSecret,
        #[Autowire('%env(WHATSAPP_PHONE_NUMBER_ID)%')]
        private string $phoneNumberId,
        #[Autowire('%env(WHATSAPP_ACCESS_TOKEN)%')]
        private string $envAccessToken
    ) {
    }

    public function generateVerifyToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function buildWebhookUrl(): string
    {
        return $this->router->generate('whatsapp_webhook_verify', [], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    public function encryptToken(string $plainToken): string
    {
        $iv = random_bytes(openssl_cipher_iv_length(self::CIPHER_ALGO));
        $key = hash('sha256', $this->appSecret, true);
        $tag = '';
        
        $ciphertext = openssl_encrypt(
            $plainToken,
            self::CIPHER_ALGO,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('Failed to encrypt token.');
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    public function decryptToken(string $encryptedTokenBase64): string
    {
        $data = base64_decode($encryptedTokenBase64);
        $ivLength = openssl_cipher_iv_length(self::CIPHER_ALGO);
        $tagLength = 16;
        
        if (strlen($data) < $ivLength + $tagLength) {
            throw new \RuntimeException('Invalid encrypted token format.');
        }

        $iv = substr($data, 0, $ivLength);
        $tag = substr($data, $ivLength, $tagLength);
        $ciphertext = substr($data, $ivLength + $tagLength);
        $key = hash('sha256', $this->appSecret, true);

        $plainText = openssl_decrypt(
            $ciphertext,
            self::CIPHER_ALGO,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plainText === false) {
            throw new \RuntimeException('Failed to decrypt token.');
        }

        return $plainText;
    }

    public function maskToken(string $token): string
    {
        $length = strlen($token);
        if ($length <= 4) {
            return str_repeat('*', $length);
        }
        return str_repeat('*', $length - 4) . substr($token, -4);
    }

    public function validateWithMetaApi(string $businessAccountId, string $accessToken): array
    {
        try {
            $response = $this->httpClient->request('GET', "https://graph.facebook.com/v21.0/{$businessAccountId}", [
                'headers' => [
                    'Authorization' => "Bearer {$accessToken}",
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $content = $response->toArray(false);

            if ($statusCode >= 200 && $statusCode < 300) {
                return ['success' => true, 'data' => $content];
            }

            return [
                'success' => false,
                'error' => $content['error']['message'] ?? 'Unknown error from Meta API',
                'code' => $statusCode
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function saveConnection(string $businessAccountId, string $plainAccessToken): WhatsAppConnection
    {
        if (empty($businessAccountId) || empty($plainAccessToken)) {
            throw new \InvalidArgumentException('Business Account ID and Access Token are required.');
        }

        $connection = $this->getConnection() ?? new WhatsAppConnection();
        
        $connection->setBusinessAccountId($businessAccountId);
        $connection->setEncryptedAccessToken($this->encryptToken($plainAccessToken));
        
        if (!$connection->getVerifyToken()) {
            $connection->setVerifyToken($this->generateVerifyToken());
        }

        if (!$connection->getWebhookUrl()) {
            $connection->setWebhookUrl($this->buildWebhookUrl());
        }

        $connection->setStatus('active');

        $this->entityManager->persist($connection);
        $this->entityManager->flush();

        return $connection;
    }

    public function getConnection(): ?WhatsAppConnection
    {
        return $this->entityManager->getRepository(WhatsAppConnection::class)->findOneBy([]);
    }

    private function getAccessToken(): string
    {
        $connection = $this->getConnection();
        if ($connection && $connection->getEncryptedAccessToken()) {
            try {
                return $this->decryptToken($connection->getEncryptedAccessToken());
            } catch (\Exception $e) {
                // Fallback to env
            }
        }
        return $this->envAccessToken;
    }

    public function sendMessage(string $to, string $text): array
    {
        $url = "https://graph.facebook.com/v21.0/{$this->phoneNumberId}/messages";
        
        $accessToken = $this->getAccessToken();

        $response = $this->httpClient->request('POST', $url, [
            'headers' => [
                'Authorization' => "Bearer {$accessToken}",
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $to,
                'type' => 'text',
                'text' => [
                    'preview_url' => false,
                    'body' => $text,
                ]
            ]
        ]);

        $content = $response->toArray();
        if ($response->getStatusCode() >= 400) {
             throw new \RuntimeException($content['error']['message'] ?? 'Failed to send message');
        }
        
        return $content;
    }
}
