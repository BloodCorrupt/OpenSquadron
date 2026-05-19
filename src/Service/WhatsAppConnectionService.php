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

    public function saveConnection(string $businessAccountId, string $plainAccessToken, ?string $phoneNumberId = null): WhatsAppConnection
    {
        if (empty($businessAccountId) || empty($plainAccessToken)) {
            throw new \InvalidArgumentException('Business Account ID and Access Token are required.');
        }

        $connection = $this->getConnection() ?? new WhatsAppConnection();
        
        $connection->setBusinessAccountId($businessAccountId);
        $connection->setEncryptedAccessToken($this->encryptToken($plainAccessToken));
        
        if ($phoneNumberId !== null) {
            $connection->setPhoneNumberId($phoneNumberId);
        }
        
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
        $connection = $this->getConnection();
        $phoneId = ($connection && $connection->getPhoneNumberId()) ? $connection->getPhoneNumberId() : $this->phoneNumberId;
        
        $url = "https://graph.facebook.com/v21.0/{$phoneId}/messages";
        
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

    public function downloadMedia(string $mediaId, string $uploadDir): ?string
    {
        $accessToken = $this->getAccessToken();
        
        // 1. Get media URL
        $response = $this->httpClient->request('GET', "https://graph.facebook.com/v21.0/{$mediaId}", [
            'headers' => [
                'Authorization' => "Bearer {$accessToken}",
            ],
        ]);
        
        if ($response->getStatusCode() >= 400) {
            return null;
        }
        
        $data = $response->toArray();
        $url = $data['url'] ?? null;
        $mimeType = $data['mime_type'] ?? '';
        
        if (!$url) return null;
        
        // 2. Download the actual file
        $fileResponse = $this->httpClient->request('GET', $url, [
            'headers' => [
                'Authorization' => "Bearer {$accessToken}",
            ],
        ]);
        
        if ($fileResponse->getStatusCode() >= 400) {
            return null;
        }
        
        // Determine extension from mime type
        $extension = 'bin';
        if (str_contains($mimeType, 'jpeg') || str_contains($mimeType, 'jpg')) $extension = 'jpg';
        elseif (str_contains($mimeType, 'png')) $extension = 'png';
        elseif (str_contains($mimeType, 'webp')) $extension = 'webp';
        elseif (str_contains($mimeType, 'audio/ogg') || str_contains($mimeType, 'audio/opus')) $extension = 'ogg';
        elseif (str_contains($mimeType, 'audio/mpeg')) $extension = 'mp3';
        elseif (str_contains($mimeType, 'audio/mp4')) $extension = 'm4a';
        
        $filename = uniqid('media_') . '.' . $extension;
        $filepath = rtrim($uploadDir, '/') . '/' . $filename;
        
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        file_put_contents($filepath, $fileResponse->getContent());
        
        return 'uploads/whatsapp_media/' . $filename;
    }

    public function sendMediaMessage(string $to, string $type, string $fileUrl): array
    {
        $connection = $this->getConnection();
        $phoneId = ($connection && $connection->getPhoneNumberId()) ? $connection->getPhoneNumberId() : $this->phoneNumberId;
        
        $url = "https://graph.facebook.com/v21.0/{$phoneId}/messages";
        $accessToken = $this->getAccessToken();

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => $type,
            $type => [
                'link' => $fileUrl,
            ]
        ];

        $response = $this->httpClient->request('POST', $url, [
            'headers' => [
                'Authorization' => "Bearer {$accessToken}",
                'Content-Type' => 'application/json',
            ],
            'json' => $payload
        ]);

        $content = $response->toArray();
        if ($response->getStatusCode() >= 400) {
             throw new \RuntimeException($content['error']['message'] ?? 'Failed to send media message');
        }
        
        return $content;
    }

    public function createTemplate(string $name, string $language, string $category, string $bodyText, ?string $headerText = null, ?string $footerText = null): array
    {
        $connection = $this->getConnection();
        if (!$connection) {
            throw new \RuntimeException('No WhatsApp connection configured.');
        }
        
        $businessAccountId = $connection->getBusinessAccountId();
        $accessToken = $this->getAccessToken();
        
        $url = "https://graph.facebook.com/v21.0/{$businessAccountId}/message_templates";
        
        $components = [];
        
        if ($headerText) {
            $components[] = [
                'type' => 'HEADER',
                'format' => 'TEXT',
                'text' => $headerText,
            ];
        }
        
        $components[] = [
            'type' => 'BODY',
            'text' => $bodyText,
        ];
        
        if ($footerText) {
            $components[] = [
                'type' => 'FOOTER',
                'text' => $footerText,
            ];
        }
        
        $payload = [
            'name' => strtolower(str_replace(' ', '_', $name)),
            'language' => $language,
            'category' => strtoupper($category),
            'components' => $components,
        ];
        
        $response = $this->httpClient->request('POST', $url, [
            'headers' => [
                'Authorization' => "Bearer {$accessToken}",
                'Content-Type' => 'application/json',
            ],
            'json' => $payload
        ]);
        
        $data = $response->toArray(false);
        
        if ($response->getStatusCode() >= 400) {
            $errorMsg = $data['error']['error_user_msg'] ?? $data['error']['message'] ?? 'Failed to create template on Meta.';
            throw new \RuntimeException($errorMsg);
        }
        
        return $data;
    }

    public function syncTemplates(): array
    {
        $connection = $this->getConnection();
        if (!$connection) {
            throw new \RuntimeException('No WhatsApp connection configured.');
        }
        
        $businessAccountId = $connection->getBusinessAccountId();
        $accessToken = $this->getAccessToken();
        
        $url = "https://graph.facebook.com/v21.0/{$businessAccountId}/message_templates?limit=100";
        
        $response = $this->httpClient->request('GET', $url, [
            'headers' => [
                'Authorization' => "Bearer {$accessToken}",
            ],
        ]);
        
        if ($response->getStatusCode() >= 400) {
            $content = $response->toArray(false);
            throw new \RuntimeException($content['error']['message'] ?? 'Failed to sync templates');
        }
        
        $data = $response->toArray();
        $templates = $data['data'] ?? [];
        
        $repo = $this->entityManager->getRepository(\App\Entity\MessageTemplate::class);
        $syncedCount = 0;
        
        // Truncate existing templates to ensure exact sync
        $this->entityManager->createQuery('DELETE FROM App\Entity\MessageTemplate')->execute();
        
        foreach ($templates as $tpl) {
            if ($tpl['status'] !== 'APPROVED') continue;
            
            $entity = new \App\Entity\MessageTemplate();
            $entity->setName($tpl['name']);
            $entity->setLanguage($tpl['language']);
            $entity->setStatus($tpl['status']);
            $entity->setCategory($tpl['category']);
            $entity->setComponents($tpl['components']);
            
            $this->entityManager->persist($entity);
            $syncedCount++;
        }
        
        $this->entityManager->flush();
        
        return ['success' => true, 'count' => $syncedCount];
    }

    public function sendTemplateMessage(string $to, string $templateName, string $languageCode): array
    {
        $connection = $this->getConnection();
        $phoneId = ($connection && $connection->getPhoneNumberId()) ? $connection->getPhoneNumberId() : $this->phoneNumberId;
        
        $url = "https://graph.facebook.com/v21.0/{$phoneId}/messages";
        $accessToken = $this->getAccessToken();

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => [
                    'code' => $languageCode
                ]
            ]
        ];

        $response = $this->httpClient->request('POST', $url, [
            'headers' => [
                'Authorization' => "Bearer {$accessToken}",
                'Content-Type' => 'application/json',
            ],
            'json' => $payload
        ]);

        $content = $response->toArray(false);
        if ($response->getStatusCode() >= 400) {
             throw new \RuntimeException($content['error']['message'] ?? 'Failed to send template message');
        }
        
        return $content;
    }
}
