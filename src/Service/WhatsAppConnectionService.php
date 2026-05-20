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

    // ───────────────────────── Connection CRUD ─────────────────────────

    /**
     * Return ALL connections ordered newest-first.
     * @return WhatsAppConnection[]
     */
    public function getAllConnections(): array
    {
        return $this->entityManager
            ->getRepository(WhatsAppConnection::class)
            ->findBy([], ['id' => 'DESC']);
    }

    /**
     * Return the default (first active) connection — backward-compatible.
     */
    public function getDefaultConnection(): ?WhatsAppConnection
    {
        return $this->entityManager
            ->getRepository(WhatsAppConnection::class)
            ->findOneBy(['status' => 'active'], ['id' => 'ASC']);
    }

    /**
     * Legacy alias so existing callers still compile.
     */
    public function getConnection(): ?WhatsAppConnection
    {
        return $this->getDefaultConnection();
    }

    public function getConnectionById(int $id): ?WhatsAppConnection
    {
        return $this->entityManager
            ->getRepository(WhatsAppConnection::class)
            ->find($id);
    }

    /**
     * Lookup by Meta's phone_number_id — used for webhook routing.
     */
    public function getConnectionByPhoneNumberId(string $phoneNumberId): ?WhatsAppConnection
    {
        return $this->entityManager
            ->getRepository(WhatsAppConnection::class)
            ->findOneBy(['phoneNumberId' => $phoneNumberId]);
    }

    /**
     * Save a new connection or update an existing one.
     */
    public function saveConnection(
        string $businessAccountId,
        string $plainAccessToken,
        ?string $phoneNumberId = null,
        ?string $label = null,
        ?string $phoneNumber = null,
        ?int $existingId = null
    ): WhatsAppConnection {
        if (empty($businessAccountId) || empty($plainAccessToken)) {
            throw new \InvalidArgumentException('Business Account ID and Access Token are required.');
        }

        $connection = $existingId
            ? $this->getConnectionById($existingId)
            : new WhatsAppConnection();

        if (!$connection) {
            $connection = new WhatsAppConnection();
        }

        $connection->setBusinessAccountId($businessAccountId);
        $connection->setEncryptedAccessToken($this->encryptToken($plainAccessToken));

        if ($phoneNumberId !== null) {
            $connection->setPhoneNumberId($phoneNumberId);
        }
        if ($label !== null) {
            $connection->setLabel($label);
        }
        if ($phoneNumber !== null) {
            $connection->setPhoneNumber($phoneNumber);
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

    /**
     * Update an existing connection — only overwrites the access token if a new one is provided.
     */
    public function updateConnection(
        int $id,
        string $businessAccountId,
        ?string $plainAccessToken,
        ?string $phoneNumberId,
        ?string $label,
        ?string $phoneNumber
    ): ?WhatsAppConnection {
        $connection = $this->getConnectionById($id);
        if (!$connection) {
            return null;
        }

        $connection->setBusinessAccountId($businessAccountId);

        if ($plainAccessToken !== null && $plainAccessToken !== '') {
            $connection->setEncryptedAccessToken($this->encryptToken($plainAccessToken));
        }
        if ($phoneNumberId !== null) {
            $connection->setPhoneNumberId($phoneNumberId);
        }
        if ($label !== null) {
            $connection->setLabel($label);
        }
        if ($phoneNumber !== null) {
            $connection->setPhoneNumber($phoneNumber);
        }

        $this->entityManager->flush();

        return $connection;
    }

    public function deleteConnection(int $id): bool
    {
        $connection = $this->getConnectionById($id);
        if (!$connection) {
            return false;
        }
        $this->entityManager->remove($connection);
        $this->entityManager->flush();
        return true;
    }

    // ───────────────────────── Crypto ─────────────────────────

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

    // ───────────────────────── Meta API ─────────────────────────

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

    // ───────────────────────── Messaging ─────────────────────────

    /**
     * Resolve access token from a specific connection, or fall back to default/env.
     */
    private function getAccessToken(?WhatsAppConnection $connection = null): string
    {
        $conn = $connection ?? $this->getDefaultConnection();
        if ($conn && $conn->getEncryptedAccessToken()) {
            try {
                return $this->decryptToken($conn->getEncryptedAccessToken());
            } catch (\Exception $e) {
                // Fallback to env
            }
        }
        return $this->envAccessToken;
    }

    /**
     * Resolve phone number ID from a specific connection, or fall back to default/env.
     */
    private function resolvePhoneId(?WhatsAppConnection $connection = null): string
    {
        $conn = $connection ?? $this->getDefaultConnection();
        if ($conn && $conn->getPhoneNumberId()) {
            return $conn->getPhoneNumberId();
        }
        return $this->phoneNumberId;
    }

    public function sendMessage(string $to, string $text, ?WhatsAppConnection $connection = null): array
    {
        $phoneId = $this->resolvePhoneId($connection);
        $url = "https://graph.facebook.com/v21.0/{$phoneId}/messages";
        $accessToken = $this->getAccessToken($connection);

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

    public function downloadMedia(string $mediaId, string $uploadDir, ?WhatsAppConnection $connection = null): ?string
    {
        $accessToken = $this->getAccessToken($connection);
        
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

    public function sendMediaMessage(string $to, string $type, string $fileUrl, ?WhatsAppConnection $connection = null): array
    {
        $phoneId = $this->resolvePhoneId($connection);
        $url = "https://graph.facebook.com/v21.0/{$phoneId}/messages";
        $accessToken = $this->getAccessToken($connection);

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

    public function createTemplate(string $name, string $language, string $category, string $bodyText, ?string $headerText = null, ?string $footerText = null, ?WhatsAppConnection $connection = null): array
    {
        $conn = $connection ?? $this->getDefaultConnection();
        if (!$conn) {
            throw new \RuntimeException('No WhatsApp connection configured.');
        }
        
        $businessAccountId = $conn->getBusinessAccountId();
        $accessToken = $this->getAccessToken($conn);
        
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

    public function syncTemplates(?WhatsAppConnection $connection = null): array
    {
        $conn = $connection ?? $this->getDefaultConnection();
        if (!$conn) {
            throw new \RuntimeException('No WhatsApp connection configured.');
        }
        
        $businessAccountId = $conn->getBusinessAccountId();
        $accessToken = $this->getAccessToken($conn);
        
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

    public function sendTemplateMessage(string $to, string $templateName, string $languageCode, ?WhatsAppConnection $connection = null): array
    {
        $phoneId = $this->resolvePhoneId($connection);
        $url = "https://graph.facebook.com/v21.0/{$phoneId}/messages";
        $accessToken = $this->getAccessToken($connection);

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
