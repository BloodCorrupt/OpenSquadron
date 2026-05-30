<?php

namespace App\Service;

use App\Entity\WhatsAppConnection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use App\Service\R2SettingsService;
use App\Service\R2StorageService;

class WhatsAppConnectionService
{
    private const CIPHER_ALGO = 'aes-256-gcm';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private HttpClientInterface $httpClient,
        private RouterInterface $router,
        private R2SettingsService $r2SettingsService,
        private R2StorageService $r2StorageService,
        #[Autowire(env: 'APP_SECRET')]
        private string $appSecret
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
        string $phoneNumberId,
        ?string $label = null,
        ?string $phoneNumber = null,
        ?int $existingId = null
    ): WhatsAppConnection {
        if (empty($businessAccountId) || empty($plainAccessToken)) {
            throw new \InvalidArgumentException('Business Account ID and Access Token are required.');
        }
        if (empty($phoneNumberId)) {
            throw new \InvalidArgumentException('Phone Number ID is required.');
        }

        $connection = $existingId
            ? $this->getConnectionById($existingId)
            : new WhatsAppConnection();

        if (!$connection) {
            $connection = new WhatsAppConnection();
        }

        $connection->setBusinessAccountId($businessAccountId);
        $connection->setEncryptedAccessToken($this->encryptToken($plainAccessToken));
        $connection->setPhoneNumberId($phoneNumberId);

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
        string $phoneNumberId,
        ?string $label,
        ?string $phoneNumber
    ): ?WhatsAppConnection {
        if (empty($phoneNumberId)) {
            throw new \InvalidArgumentException('Phone Number ID is required.');
        }

        $connection = $this->getConnectionById($id);
        if (!$connection) {
            return null;
        }

        $connection->setBusinessAccountId($businessAccountId);

        if ($plainAccessToken !== null && $plainAccessToken !== '') {
            $connection->setEncryptedAccessToken($this->encryptToken($plainAccessToken));
        }
        $connection->setPhoneNumberId($phoneNumberId);

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
        
        // Manually cascade delete Subscribers and Messages to prevent 1451 Foreign Key violations
        // since the Message table doesn't have an ON DELETE CASCADE at the database level.
        $subscribers = $this->entityManager->getRepository(\App\Entity\Subscriber::class)->findBy(['whatsAppConnection' => $connection]);
        foreach ($subscribers as $sub) {
            $messages = $this->entityManager->getRepository(\App\Entity\Message::class)->findBy(['subscriber' => $sub]);
            foreach ($messages as $msg) {
                $this->entityManager->remove($msg);
            }
            $this->entityManager->remove($sub);
        }

        $this->entityManager->remove($connection);
        $this->entityManager->flush();
        return true;
    }


    /**
     * Synchronize connections from Meta Embedded Signup using the provided access token.
     * This handles the Coexistence (WhatsApp Business App Onboarding) flow which relies on Code Exchange.
     */
    public function syncEmbeddedSignupConnections(string $oauthCode, string $appId, string $appSecret, int $limitSlots = 999, ?string $currentUrl = null): array
    {
        // 1. Exchange code for access token
        $response = $this->httpClient->request('GET', 'https://graph.facebook.com/v21.0/oauth/access_token', [
            'query' => [
                'client_id' => $appId,
                'client_secret' => $appSecret,
                'code' => $oauthCode,
            ]
        ]);
        
        if ($response->getStatusCode() >= 400) {
            $data = $response->toArray(false);
            throw new \RuntimeException('Failed to exchange code: ' . ($data['error']['message'] ?? 'Unknown error'));
        }

        $tokenData = $response->toArray();
        $accessToken = $tokenData['access_token'];

        // 2. Fetch WABAs shared via this token
        $wabaResponse = $this->httpClient->request('GET', 'https://graph.facebook.com/v21.0/me/client_whatsapp_business_accounts', [
            'headers' => [
                'Authorization' => "Bearer {$accessToken}"
            ]
        ]);

        $wabaData = $wabaResponse->toArray(false);
        if ($wabaResponse->getStatusCode() >= 400) {
            // Fallback: maybe it's not a client WABA, try normal accounts
            $wabaData['data'] = [];
        }

        $wabas = $wabaData['data'] ?? [];
        if (empty($wabas)) {
            throw new \RuntimeException('No WhatsApp Business Accounts found. Please ensure you selected a business account during setup.');
        }

        $syncedConnections = [];

        foreach ($wabas as $waba) {
            $wabaId = $waba['id'];
            
            // Get phone numbers for this WABA
            $phonesResponse = $this->httpClient->request('GET', "https://graph.facebook.com/v21.0/{$wabaId}/phone_numbers", [
                'headers' => [
                    'Authorization' => "Bearer {$accessToken}"
                ]
            ]);

            $phonesData = $phonesResponse->toArray(false);
            $phones = $phonesData['data'] ?? [];

            foreach ($phones as $phone) {
                $phoneNumberId = $phone['id'];
                $displayPhoneNumber = $phone['display_phone_number'] ?? null;
                $verifiedName = $phone['verified_name'] ?? $displayPhoneNumber ?? 'WhatsApp Connection';

                $existing = $this->getConnectionByPhoneNumberId($phoneNumberId);
                if ($existing) {
                    $conn = $this->updateConnection($existing->getId(), $wabaId, $accessToken, $phoneNumberId, $verifiedName, $displayPhoneNumber);
                } else {
                    $conn = $this->saveConnection($wabaId, $accessToken, $phoneNumberId, $verifiedName, $displayPhoneNumber);
                }
                $syncedConnections[] = ['id' => $conn->getId(), 'name' => $verifiedName];
            }
        }

        return $syncedConnections;
    }

    /**
     * Sync a connection using data captured from the WA_EMBEDDED_SIGNUP message event.
     * Uses the System User Access Token from MetaSetting instead of exchanging an OAuth code.
     */
    public function syncFromEmbeddedSignupEvent(string $wabaId, string $phoneNumberId, string $systemUserToken, int $limitSlots = 999): array
    {
        // 1. Fetch the phone number details from the Graph API using the System User Token
        $phoneUrl = "https://graph.facebook.com/v21.0/{$phoneNumberId}";
        $phoneResponse = $this->httpClient->request('GET', $phoneUrl, [
            'query' => [
                'access_token' => $systemUserToken,
                'fields' => 'id,display_phone_number,verified_name,quality_rating'
            ]
        ]);

        $phoneData = $phoneResponse->toArray(false);
        if ($phoneResponse->getStatusCode() >= 400) {
            throw new \RuntimeException('Failed to fetch phone number details: ' . ($phoneData['error']['message'] ?? 'Unknown error'));
        }

        $displayPhoneNumber = $phoneData['display_phone_number'] ?? null;
        $verifiedName = $phoneData['verified_name'] ?? $displayPhoneNumber ?? 'WhatsApp Connection';

        // We no longer automatically register with a hardcoded '123456' PIN.
        // The connection will be saved and the user can register it manually with a custom PIN via the dashboard.

        // 2.5 Subscribe the App to the WABA's webhooks so we actually receive incoming messages
        try {
            $subscribeUrl = "https://graph.facebook.com/v21.0/{$wabaId}/subscribed_apps";
            $this->httpClient->request('POST', $subscribeUrl, [
                'headers' => [
                    'Authorization' => "Bearer {$systemUserToken}"
                ]
            ]);
        } catch (\Exception $e) {
            // Silently continue, though this might mean webhooks won't fire until manually subscribed
        }

        // 3. Check if this phone number already exists in our database
        $existing = $this->getConnectionByPhoneNumberId($phoneNumberId);

        $syncedConnections = [];

        if ($existing) {
            // Update existing connection
            $conn = $this->updateConnection(
                $existing->getId(),
                $wabaId,
                $systemUserToken,
                $phoneNumberId,
                $verifiedName,
                $displayPhoneNumber
            );
            $syncedConnections[] = ['id' => $conn->getId(), 'name' => $verifiedName];
        } else {
            // Create new connection
            $conn = $this->saveConnection(
                $wabaId,
                $systemUserToken,
                $phoneNumberId,
                $verifiedName,
                $displayPhoneNumber
            );
            $syncedConnections[] = ['id' => $conn->getId(), 'name' => $verifiedName];
        }

        return $syncedConnections;
    }

    /**
     * Manually register a phone number on the Meta network using a 6-digit PIN.
     */
    public function registerPhoneNumber(int $connectionId, string $pin): void
    {
        $connection = $this->getConnectionById($connectionId);
        if (!$connection) {
            throw new \RuntimeException('Connection not found.');
        }

        $phoneNumberId = $connection->getPhoneNumberId();
        if (!$phoneNumberId) {
            throw new \RuntimeException('No phone number ID associated with this connection.');
        }

        $encryptedToken = $connection->getEncryptedAccessToken();
        if (!$encryptedToken) {
            throw new \RuntimeException('No access token found for this connection.');
        }

        $accessToken = $this->decryptToken($encryptedToken);

        $registerUrl = "https://graph.facebook.com/v21.0/{$phoneNumberId}/register";
        $response = $this->httpClient->request('POST', $registerUrl, [
            'headers' => [
                'Authorization' => "Bearer {$accessToken}",
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'messaging_product' => 'whatsapp',
                'pin' => $pin
            ]
        ]);

        $data = $response->toArray(false);
        if ($response->getStatusCode() >= 400 && !isset($data['success'])) {
            $errorMsg = $data['error']['message'] ?? 'Unknown error';
            throw new \RuntimeException("Meta API Error: " . $errorMsg);
        }
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
                throw new \RuntimeException('Failed to decrypt WhatsApp access token from database.');
            }
        }
        throw new \RuntimeException('No WhatsApp connection found in the database. Please connect a WhatsApp business account.');
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
        throw new \RuntimeException('No WhatsApp connection found. Cannot resolve phone number ID.');
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

    public function downloadMedia(string $mediaId, string $uploadDir = '', ?WhatsAppConnection $connection = null): ?string
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

        // Resolve active R2 settings for connection owner
        if (!$connection) {
            $connection = $this->getDefaultConnection();
        }
        $owner = $connection ? $connection->getOwner() : null;

        $r2Settings = null;
        if ($owner) {
            $r2Settings = $this->r2SettingsService->getActiveSettings($owner);
        }

        if ($r2Settings && $this->r2SettingsService->isComplete($r2Settings)) {
            $objectKey = "whatsapp/{$filename}";
            $uploadedUrl = $this->r2StorageService->uploadContent(
                $r2Settings,
                $fileResponse->getContent(),
                $objectKey,
                $mimeType
            );
            if ($uploadedUrl) {
                return $uploadedUrl;
            }
        }

        // Fallback to local storage
        if (empty($uploadDir)) {
            $uploadDir = __DIR__ . '/../../public/uploads/whatsapp_media';
        }
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        $filepath = rtrim($uploadDir, '/') . '/' . $filename;
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

    public function createTemplate(string $name, string $language, string $category, string $bodyText, ?string $headerText = null, ?string $footerText = null, ?WhatsAppConnection $connection = null, ?array $customComponents = null): array
    {
        $conn = $connection ?? $this->getDefaultConnection();
        if (!$conn) {
            throw new \RuntimeException('No WhatsApp connection configured.');
        }
        
        $businessAccountId = $conn->getBusinessAccountId();
        $accessToken = $this->getAccessToken($conn);
        
        $url = "https://graph.facebook.com/v21.0/{$businessAccountId}/message_templates";
        
        $components = $customComponents ?? [];
        
        if (empty($components)) {
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
        
        // Truncate existing templates for this connection to ensure exact sync
        $this->entityManager->createQuery('DELETE FROM App\Entity\MessageTemplate t WHERE t.whatsAppConnection = :conn')
            ->setParameter('conn', $conn)
            ->execute();
        
        foreach ($templates as $tpl) {
            if ($tpl['status'] !== 'APPROVED') continue;
            
            $entity = new \App\Entity\MessageTemplate();
            $entity->setName($tpl['name']);
            $entity->setLanguage($tpl['language']);
            $entity->setStatus($tpl['status']);
            $entity->setCategory($tpl['category']);
            $entity->setComponents($tpl['components']);
            $entity->setWhatsAppConnection($conn);
            
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
