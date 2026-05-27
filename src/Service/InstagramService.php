<?php

namespace App\Service;

use App\Entity\InstagramConnection;
use App\Entity\MetaSetting;
use App\Entity\Admin;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class InstagramService
{
    private const CIPHER_ALGO = 'aes-256-gcm';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?\App\Service\TenantContext $tenantContext,
        private HttpClientInterface $httpClient,
        private RouterInterface $router,
        #[Autowire(env: 'APP_SECRET')]
        private string $appSecret
    ) {
    }

    // ───────────────────────── Connection CRUD ─────────────────────────

    /**
     * Get the active MetaSetting for the current tenant.
     */
    public function getSetting(): ?MetaSetting
    {
        return $this->entityManager
            ->getRepository(MetaSetting::class)
            ->findOneBy([]);
    }

    /**
     * Get the global MetaSetting from the super_admin.
     */
    public function getGlobalSetting(): ?MetaSetting
    {
        $em = $this->entityManager;
        $isTenantFilterEnabled = false;

        if ($this->tenantContext) {
            $filters = $em->getFilters();
            $isTenantFilterEnabled = $filters->has('tenant_filter') && $filters->isEnabled('tenant_filter');
            if ($isTenantFilterEnabled) {
                $this->tenantContext->disableTenantFilter();
            }
        } else {
            // Fallback if TenantContext wasn't injected
            $filters = $em->getFilters();
            $isTenantFilterEnabled = $filters->has('tenant_filter') && $filters->isEnabled('tenant_filter');
            if ($isTenantFilterEnabled) {
                $filters->disable('tenant_filter');
            }
        }

        $superAdmin = $em->getRepository(Admin::class)->findOneBy(['accountType' => 'super_admin']);
        $globalSetting = null;
        if ($superAdmin) {
            $globalSetting = $em->getRepository(MetaSetting::class)->findOneBy(['owner' => $superAdmin]);
        }

        if ($isTenantFilterEnabled) {
            if ($this->tenantContext && $this->tenantContext->getCurrentOwner()) {
                $this->tenantContext->enableTenantFilter($this->tenantContext->getCurrentOwner()->getId());
            } else {
                $filters->enable('tenant_filter');
            }
        }

        return $globalSetting;
    }

    /**
     * Get the effective MetaSetting (Tenant's if configured, otherwise Global).
     */
    public function getEffectiveSetting(): ?MetaSetting
    {
        $setting = $this->getSetting();
        if ($setting && $setting->getAppId() && $setting->getEncryptedAppSecret()) {
            return $setting;
        }

        return $this->getGlobalSetting();
    }

    /**
     * Return ALL Instagram connections ordered newest-first.
     * @return InstagramConnection[]
     */
    public function getAllConnections(): array
    {
        return $this->entityManager
            ->getRepository(InstagramConnection::class)
            ->findBy([], ['id' => 'DESC']);
    }

    /**
     * Return the default (first active) Instagram connection.
     */
    public function getDefaultConnection(): ?InstagramConnection
    {
        return $this->entityManager
            ->getRepository(InstagramConnection::class)
            ->findOneBy(['status' => 'active'], ['id' => 'ASC']);
    }

    public function getConnectionById(int $id): ?InstagramConnection
    {
        return $this->entityManager
            ->getRepository(InstagramConnection::class)
            ->find($id);
    }

    /**
     * Lookup by Instagram Page ID — used for webhook routing.
     */
    public function getConnectionByPageId(string $pageId): ?InstagramConnection
    {
        return $this->entityManager
            ->getRepository(InstagramConnection::class)
            ->findOneBy(['pageId' => $pageId]);
    }

    /**
     * Save a new Instagram connection or update an existing one.
     */
    public function saveConnection(
        string $pageId,
        string $plainPageAccessToken,
        string $appId,
        string $plainAppSecret,
        ?string $pageName = null,
        ?string $label = null,
        ?int $existingId = null,
        ?string $linkedFacebookPageId = null
    ): InstagramConnection {
        if (empty($pageId) || empty($plainPageAccessToken) || empty($appId) || empty($plainAppSecret)) {
            throw new \InvalidArgumentException('Page ID, Page Access Token, App ID, and App Secret are required.');
        }

        // If an existing ID was provided, load it; otherwise look for an existing connection
        // with the same Page ID to avoid creating duplicates when re-connecting.
        $connection = null;
        if ($existingId) {
            $connection = $this->getConnectionById($existingId);
        }
        if (!$connection) {
            $connection = $this->entityManager
                ->getRepository(InstagramConnection::class)
                ->findOneBy(['pageId' => $pageId]);
        }
        if (!$connection) {
            $connection = new InstagramConnection();
        }

        $connection->setPageId($pageId);
        $connection->setEncryptedPageAccessToken($this->encryptToken($plainPageAccessToken));
        $connection->setAppId($appId);
        $connection->setEncryptedAppSecret($this->encryptToken($plainAppSecret));

        if ($pageName !== null) {
            $connection->setPageName($pageName);
        }
        if ($label !== null) {
            $connection->setLabel($label);
        }
        if ($linkedFacebookPageId !== null) {
            $connection->setLinkedFacebookPageId($linkedFacebookPageId);
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
     * Update an existing Instagram connection.
     */
    public function updateConnection(
        int $id,
        string $pageId,
        ?string $plainPageAccessToken,
        string $appId,
        ?string $plainAppSecret,
        ?string $pageName,
        ?string $label
    ): ?InstagramConnection {
        if (empty($pageId) || empty($appId)) {
            throw new \InvalidArgumentException('Page ID and App ID are required.');
        }

        $connection = $this->getConnectionById($id);
        if (!$connection) {
            return null;
        }

        $connection->setPageId($pageId);
        $connection->setAppId($appId);

        if ($plainPageAccessToken !== null && $plainPageAccessToken !== '') {
            $connection->setEncryptedPageAccessToken($this->encryptToken($plainPageAccessToken));
        }
        if ($plainAppSecret !== null && $plainAppSecret !== '') {
            $connection->setEncryptedAppSecret($this->encryptToken($plainAppSecret));
        }
        if ($pageName !== null) {
            $connection->setPageName($pageName);
        }
        if ($label !== null) {
            $connection->setLabel($label);
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
        return $this->router->generate('instagram_webhook_verify', [], UrlGeneratorInterface::ABSOLUTE_URL);
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

    // ───────────────────────── Meta Graph API ─────────────────────────

    /**
     * Validate the Page Access Token against the Graph API.
     */
    public function validateWithGraphApi(string $pageId, string $pageAccessToken): array
    {
        try {
            $response = $this->httpClient->request('GET', "https://graph.facebook.com/v21.0/{$pageId}", [
                'headers' => [
                    'Authorization' => "Bearer {$pageAccessToken}",
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $content = $response->toArray(false);

            if ($statusCode >= 200 && $statusCode < 300) {
                return ['success' => true, 'data' => $content];
            }

            return [
                'success' => false,
                'error' => $content['error']['message'] ?? 'Unknown error from Graph API',
                'code' => $statusCode
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ───────────────────────── Messaging (Send API) ─────────────────────────

    /**
     * Resolve the Page Access Token from a connection.
     */
    private function getAccessToken(?InstagramConnection $connection = null): string
    {
        $conn = $connection ?? $this->getDefaultConnection();
        if ($conn && $conn->getEncryptedPageAccessToken()) {
            return $this->decryptToken($conn->getEncryptedPageAccessToken());
        }
        throw new \RuntimeException('No Instagram connection with a valid access token found.');
    }

    /**
     * Send a text message to a user via the Instagram Send API.
     */
    public function sendMessage(string $psid, string $text, ?InstagramConnection $connection = null): array
    {
        $accessToken = $this->getAccessToken($connection);
        $url = "https://graph.facebook.com/v21.0/me/messages";

        $response = $this->httpClient->request('POST', $url, [
            'headers' => [
                'Authorization' => "Bearer {$accessToken}",
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'recipient' => ['id' => $psid],
                'messaging_type' => 'RESPONSE',
                'message' => [
                    'text' => $text,
                ]
            ]
        ]);

        $content = $response->toArray(false);
        if ($response->getStatusCode() >= 400) {
            throw new \RuntimeException($content['error']['message'] ?? 'Failed to send message');
        }

        return $content;
    }

    /**
     * Send an attachment (image/audio/file) to a user via the Instagram Send API.
     */
    public function sendMediaMessage(string $psid, string $type, string $fileUrl, ?InstagramConnection $connection = null): array
    {
        $accessToken = $this->getAccessToken($connection);
        $url = "https://graph.facebook.com/v21.0/me/messages";

        $payload = [
            'recipient' => ['id' => $psid],
            'messaging_type' => 'RESPONSE',
            'message' => [
                'attachment' => [
                    'type' => $type,
                    'payload' => [
                        'url' => $fileUrl,
                        'is_reusable' => true,
                    ]
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
            throw new \RuntimeException($content['error']['message'] ?? 'Failed to send media message');
        }

        return $content;
    }

    public function exchangeCodeForUserToken(string $appId, string $appSecret, string $code, string $redirectUri): string
    {
        $response = $this->httpClient->request('GET', 'https://graph.facebook.com/v21.0/oauth/access_token', [
            'query' => [
                'client_id' => $appId,
                'redirect_uri' => $redirectUri,
                'client_secret' => $appSecret,
                'code' => $code,
            ]
        ]);

        $data = $response->toArray(false);
        if ($response->getStatusCode() >= 400 || !isset($data['access_token'])) {
            throw new \RuntimeException($data['error']['message'] ?? 'Failed to exchange authorization code for access token.');
        }

        return $data['access_token'];
    }

    public function getLongLivedUserToken(string $appId, string $appSecret, string $shortLivedToken): string
    {
        $response = $this->httpClient->request('GET', 'https://graph.facebook.com/v21.0/oauth/access_token', [
            'query' => [
                'grant_type' => 'fb_exchange_token',
                'client_id' => $appId,
                'client_secret' => $appSecret,
                'fb_exchange_token' => $shortLivedToken,
            ]
        ]);

        $data = $response->toArray(false);
        if ($response->getStatusCode() >= 400 || !isset($data['access_token'])) {
            return $shortLivedToken;
        }

        return $data['access_token'];
    }

    public function getUserPages(string $userAccessToken): array
    {
        $response = $this->httpClient->request('GET', 'https://graph.facebook.com/v21.0/me/accounts', [
            'query' => [
                'access_token' => $userAccessToken,
                'fields' => 'id,name,access_token,category,tasks',
            ]
        ]);

        $data = $response->toArray(false);
        if ($response->getStatusCode() >= 400 || !isset($data['data'])) {
            throw new \RuntimeException($data['error']['message'] ?? 'Failed to retrieve Instagram pages.');
        }

        return $data['data'];
    }

    /**
     * Subscribe the Instagram Page to the App's webhook events.
     */
    public function subscribePage(string $pageId, string $pageAccessToken): array
    {
        try {
            $url = "https://graph.facebook.com/v21.0/{$pageId}/subscribed_apps";
            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Authorization' => "Bearer {$pageAccessToken}",
                ],
                'query' => [
                    'subscribed_fields' => 'messages',
                ]
            ]);

            $statusCode = $response->getStatusCode();
            $content = $response->toArray(false);

            if ($statusCode >= 200 && $statusCode < 300) {
                return ['success' => true, 'data' => $content];
            }

            return [
                'success' => false,
                'error' => $content['error']['message'] ?? 'Unknown error from Graph API',
                'code' => $statusCode
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Parses and verifies Instagram's signed_request parameter.
     */
    public function parseSignedRequest(string $signedRequest): ?array
    {
        if (strpos($signedRequest, '.') === false) {
            return null;
        }

        list($encodedSig, $payload) = explode('.', $signedRequest, 2);

        $sig = $this->base64UrlDecode($encodedSig);
        $data = json_decode($this->base64UrlDecode($payload), true);

        if (!$data) {
            return null;
        }

        if (!isset($data['algorithm']) || strtoupper($data['algorithm']) !== 'HMAC-SHA256') {
            return null;
        }

        $setting = $this->getSetting();
        if (!$setting || !$setting->getEncryptedAppSecret()) {
            return null;
        }

        try {
            $appSecret = $this->decryptToken($setting->getEncryptedAppSecret());
        } catch (\Exception $e) {
            return null;
        }

        $expectedSig = hash_hmac('sha256', $payload, $appSecret, true);
        if (!hash_equals($sig, $expectedSig)) {
            return null;
        }

        return $data;
    }

    // ───────────────────────── Social Posting API ─────────────────────────

    /**
     * Publish a text or link post to the page's feed.
     */
    public function publishFeedPost(InstagramConnection $connection, string $message, ?string $link = null): array
    {
        throw new \RuntimeException('Text-only or link-only posts are not natively supported on Instagram through this API. You must provide an image or video.');
    }

    /**
     * Publish a photo post to Instagram.
     */
    public function publishPhotoPost(InstagramConnection $connection, string $imageUrl, string $message): array
    {
        $accessToken = $this->getAccessToken($connection);
        $pageId = $connection->getPageId(); // IG User ID

        // 1. Create Media Container
        $containerUrl = "https://graph.facebook.com/v21.0/{$pageId}/media";
        $containerRes = $this->httpClient->request('POST', $containerUrl, [
            'headers' => [
                'Authorization' => "Bearer {$accessToken}",
                'Content-Type' => 'application/json',
            ],
            'json' => ['image_url' => $imageUrl, 'caption' => $message]
        ]);
        
        $containerData = $containerRes->toArray(false);
        if ($containerRes->getStatusCode() >= 400 || empty($containerData['id'])) {
            throw new \RuntimeException($containerData['error']['message'] ?? 'Failed to create Instagram photo container.');
        }
        $containerId = $containerData['id'];

        // 2. Publish Media with Retry for processing delay (Error 9007)
        $publishUrl = "https://graph.facebook.com/v21.0/{$pageId}/media_publish";
        $attempts = 0;
        $content = [];
        $publishRes = null;
        
        while ($attempts < 4) {
            $publishRes = $this->httpClient->request('POST', $publishUrl, [
                'headers' => [
                    'Authorization' => "Bearer {$accessToken}",
                    'Content-Type' => 'application/json',
                ],
                'json' => ['creation_id' => $containerId]
            ]);
            
            $content = $publishRes->toArray(false);
            if ($publishRes->getStatusCode() < 400) {
                break;
            }
            
            $errorMessage = $content['error']['message'] ?? '';
            if (strpos($errorMessage, 'Media ID is not available') !== false || ($content['error']['code'] ?? 0) == 9007) {
                $attempts++;
                sleep(3);
                continue;
            }
            break; // other error
        }
        
        if ($publishRes->getStatusCode() >= 400) {
            throw new \RuntimeException($content['error']['message'] ?? 'Failed to publish Instagram photo.');
        }

        return $content;
    }

    /**
     * Publish a video post to Instagram.
     */
    public function publishVideoPost(InstagramConnection $connection, string $videoUrl, string $message): array
    {
        $accessToken = $this->getAccessToken($connection);
        $pageId = $connection->getPageId();

        // 1. Create Media Container
        $containerUrl = "https://graph.facebook.com/v21.0/{$pageId}/media";
        $containerRes = $this->httpClient->request('POST', $containerUrl, [
            'headers' => [
                'Authorization' => "Bearer {$accessToken}",
                'Content-Type' => 'application/json',
            ],
            'json' => ['media_type' => 'REELS', 'video_url' => $videoUrl, 'caption' => $message]
        ]);
        
        $containerData = $containerRes->toArray(false);
        if ($containerRes->getStatusCode() >= 400 || empty($containerData['id'])) {
            throw new \RuntimeException($containerData['error']['message'] ?? 'Failed to create Instagram video container.');
        }
        $containerId = $containerData['id'];

        // 2. Publish Media with Retry for processing delay (Error 9007)
        $publishUrl = "https://graph.facebook.com/v21.0/{$pageId}/media_publish";
        $attempts = 0;
        $content = [];
        $publishRes = null;
        
        while ($attempts < 6) { // Videos can take a bit longer
            $publishRes = $this->httpClient->request('POST', $publishUrl, [
                'headers' => [
                    'Authorization' => "Bearer {$accessToken}",
                    'Content-Type' => 'application/json',
                ],
                'json' => ['creation_id' => $containerId]
            ]);
            
            $content = $publishRes->toArray(false);
            if ($publishRes->getStatusCode() < 400) {
                break;
            }
            
            $errorMessage = $content['error']['message'] ?? '';
            if (strpos($errorMessage, 'Media ID is not available') !== false || ($content['error']['code'] ?? 0) == 9007) {
                $attempts++;
                sleep(5);
                continue;
            }
            break; // other error
        }
        
        if ($publishRes->getStatusCode() >= 400) {
            throw new \RuntimeException($content['error']['message'] ?? 'Failed to publish video. Video may still be processing on Instagram, try again in a few minutes.');
        }

        return $content;
    }

    /**
     * Publish a post with a Call-To-Action (CTA) link.
     */
    public function publishCtaPost(InstagramConnection $connection, string $message, string $link, string $ctaType): array
    {
        throw new \RuntimeException('Call-to-action buttons are not supported on organic Instagram posts via the API.');
    }

    /**
     * Publish a carousel-style slide post using multi-photo attachments.
     */
    public function publishCarouselPost(InstagramConnection $connection, string $message, array $slides): array
    {
        $accessToken = $this->getAccessToken($connection);
        $pageId = $connection->getPageId();

        $mediaIds = [];
        foreach ($slides as $index => $slide) {
            $imageUrl = trim($slide['imageUrl'] ?? '');
            if ($imageUrl === '') continue;

            $url = "https://graph.facebook.com/v21.0/{$pageId}/media";
            $res = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Authorization' => "Bearer {$accessToken}",
                    'Content-Type' => 'application/json',
                ],
                'json' => ['image_url' => $imageUrl, 'is_carousel_item' => 'true']
            ]);
            
            $data = $res->toArray(false);
            if ($res->getStatusCode() < 400 && !empty($data['id'])) {
                $mediaIds[] = $data['id'];
            }
        }

        if (empty($mediaIds)) {
            throw new \RuntimeException('Failed to upload any carousel items.');
        }

        // Create Carousel Container
        $containerUrl = "https://graph.facebook.com/v21.0/{$pageId}/media";
        $containerRes = $this->httpClient->request('POST', $containerUrl, [
            'headers' => [
                'Authorization' => "Bearer {$accessToken}",
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'media_type' => 'CAROUSEL', 
                'children' => implode(',', $mediaIds), 
                'caption' => $message
            ]
        ]);
        
        $containerData = $containerRes->toArray(false);
        if ($containerRes->getStatusCode() >= 400 || empty($containerData['id'])) {
            throw new \RuntimeException($containerData['error']['message'] ?? 'Failed to create carousel container.');
        }
        $containerId = $containerData['id'];

        // Publish Carousel with Retry for processing delay (Error 9007)
        $publishUrl = "https://graph.facebook.com/v21.0/{$pageId}/media_publish";
        $attempts = 0;
        $content = [];
        $publishRes = null;
        
        while ($attempts < 5) {
            $publishRes = $this->httpClient->request('POST', $publishUrl, [
                'headers' => [
                    'Authorization' => "Bearer {$accessToken}",
                    'Content-Type' => 'application/json',
                ],
                'json' => ['creation_id' => $containerId]
            ]);
            
            $content = $publishRes->toArray(false);
            if ($publishRes->getStatusCode() < 400) {
                break;
            }
            
            $errorMessage = $content['error']['message'] ?? '';
            if (strpos($errorMessage, 'Media ID is not available') !== false || ($content['error']['code'] ?? 0) == 9007) {
                $attempts++;
                sleep(4);
                continue;
            }
            break; // other error
        }
        
        if ($publishRes->getStatusCode() >= 400) {
            throw new \RuntimeException($content['error']['message'] ?? 'Failed to publish Instagram carousel.');
        }

        return $content;
    }

    /**
     * Fetch recent posts from the page's feed via the Instagram Graph API.
     */
    public function fetchPageFeed(InstagramConnection $connection, int $limit = 50): array
    {
        $accessToken = $this->getAccessToken($connection);
        $pageId = $connection->getPageId();
        $url = "https://graph.facebook.com/v21.0/{$pageId}/media";

        $response = $this->httpClient->request('GET', $url, [
            'headers' => [
                'Authorization' => "Bearer {$accessToken}",
            ],
            'query' => [
                'fields' => 'id,caption,media_type,media_url,thumbnail_url,permalink,timestamp,comments_count,like_count',
                'limit' => $limit,
            ]
        ]);

        $content = $response->toArray(false);
        if ($response->getStatusCode() >= 400) {
            throw new \RuntimeException($content['error']['message'] ?? 'Failed to fetch page feed.');
        }

        return $content['data'] ?? [];
    }

    /**
     * Hide a comment.
     */
    public function hideComment(string $commentId, ?InstagramConnection $connection = null): array
    {
        $accessToken = $this->getAccessToken($connection);
        $url = "https://graph.facebook.com/v21.0/{$commentId}";
        $response = $this->httpClient->request('POST', $url, [
            'headers' => [
                'Authorization' => "Bearer {$accessToken}",
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'is_hidden' => true
            ]
        ]);
        $content = $response->toArray(false);
        if ($response->getStatusCode() >= 400) {
            throw new \RuntimeException($content['error']['message'] ?? 'Failed to hide comment.');
        }
        return $content;
    }

    /**
     * Delete a comment.
     */
    public function deleteComment(string $commentId, ?InstagramConnection $connection = null): array
    {
        $accessToken = $this->getAccessToken($connection);
        $url = "https://graph.facebook.com/v21.0/{$commentId}";
        $response = $this->httpClient->request('DELETE', $url, [
            'headers' => [
                'Authorization' => "Bearer {$accessToken}",
            ]
        ]);
        $content = $response->toArray(false);
        if ($response->getStatusCode() >= 400) {
            throw new \RuntimeException($content['error']['message'] ?? 'Failed to delete comment.');
        }
        return $content;
    }

    /**
     * Comment on a media post.
     */
    public function commentOnPost(string $mediaId, string $message, ?string $attachmentUrl = null, ?InstagramConnection $connection = null): array
    {
        $accessToken = $this->getAccessToken($connection);
        $url = "https://graph.facebook.com/v21.0/{$mediaId}/comments";

        $payload = [
            'message' => $message,
        ];
        if (!empty($attachmentUrl)) {
            $payload['attachment_url'] = $attachmentUrl;
        }

        $response = $this->httpClient->request('POST', $url, [
            'headers' => [
                'Authorization' => "Bearer {$accessToken}",
                'Content-Type' => 'application/json',
            ],
            'json' => $payload
        ]);

        $content = $response->toArray(false);
        if ($response->getStatusCode() >= 400) {
            throw new \RuntimeException($content['error']['message'] ?? 'Failed to comment on media post.');
        }

        return $content;
    }

    /**
     * Reply to a comment.
     */
    public function replyToComment(string $commentId, string $message, ?string $attachmentUrl = null, ?InstagramConnection $connection = null): array
    {
        $accessToken = $this->getAccessToken($connection);
        $url = "https://graph.facebook.com/v21.0/{$commentId}/replies";

        $payload = [
            'message' => $message,
        ];
        if (!empty($attachmentUrl)) {
            $payload['attachment_url'] = $attachmentUrl;
        }

        $response = $this->httpClient->request('POST', $url, [
            'headers' => [
                'Authorization' => "Bearer {$accessToken}",
                'Content-Type' => 'application/json',
            ],
            'json' => $payload
        ]);

        $content = $response->toArray(false);
        if ($response->getStatusCode() >= 400) {
            throw new \RuntimeException($content['error']['message'] ?? 'Failed to reply to comment.');
        }

        return $content;
    }

    /**
     * Send a private reply to a comment.
     */
    public function privateReplyToComment(string $commentId, string $message, ?InstagramConnection $connection = null): array
    {
        $accessToken = $this->getAccessToken($connection);
        $url = "https://graph.facebook.com/v21.0/{$commentId}/private_replies";

        $response = $this->httpClient->request('POST', $url, [
            'headers' => [
                'Authorization' => "Bearer {$accessToken}",
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'message' => $message
            ]
        ]);

        $content = $response->toArray(false);
        if ($response->getStatusCode() >= 400) {
            throw new \RuntimeException($content['error']['message'] ?? 'Failed to send private reply.');
        }

        return $content;
    }

    /**
     * Retrieve the persistent menu from Instagram for a given page connection.
     */
    public function getPersistentMenu(InstagramConnection $connection): ?array
    {
        $accessToken = $this->getAccessToken($connection);
        $url = "https://graph.facebook.com/v21.0/me/messenger_profile";

        $response = $this->httpClient->request('GET', $url, [
            'headers' => [
                'Authorization' => "Bearer {$accessToken}",
            ],
            'query' => [
                'fields' => 'persistent_menu',
                'platform' => 'instagram'
            ]
        ]);

        $content = $response->toArray(false);
        if ($response->getStatusCode() >= 400) {
            throw new \RuntimeException($content['error']['message'] ?? 'Failed to retrieve persistent menu from Instagram.');
        }

        if (isset($content['data'][0]['persistent_menu'][0]['call_to_actions'])) {
            return $content['data'][0]['persistent_menu'][0]['call_to_actions'];
        }

        return null;
    }

    /**
     * Update the persistent menu on Instagram for a given page connection.
     */
    public function setPersistentMenu(InstagramConnection $connection, array $menuItems): array
    {
        $accessToken = $this->getAccessToken($connection);
        $url = "https://graph.facebook.com/v21.0/me/messenger_profile";

        $payload = [
            'platform' => 'instagram',
            'persistent_menu' => [
                [
                    'locale' => 'default',
                    'composer_input_disabled' => false,
                    'call_to_actions' => $menuItems
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
            throw new \RuntimeException($content['error']['message'] ?? 'Failed to update persistent menu on Instagram.');
        }

        return $content;
    }

    /**
     * Delete the persistent menu on Instagram for a given page connection.
     */
    public function deletePersistentMenu(InstagramConnection $connection): array
    {
        $accessToken = $this->getAccessToken($connection);
        $url = "https://graph.facebook.com/v21.0/me/messenger_profile";

        $response = $this->httpClient->request('DELETE', $url, [
            'headers' => [
                'Authorization' => "Bearer {$accessToken}",
                'Content-Type' => 'application/json',
            ],
            'query' => [
                'platform' => 'instagram'
            ],
            'json' => [
                'fields' => ['persistent_menu']
            ]
        ]);

        $content = $response->toArray(false);
        if ($response->getStatusCode() >= 400) {
            // Silently ignore deletion errors if the menu doesn't exist
            // throw new \RuntimeException($content['error']['message'] ?? 'Failed to delete persistent menu on Instagram.');
        }

        return $content;
    }

    /**
     * Sync the persistent menu from Instagram page messenger profile back to OpenSquadron connection settings.
     */
    public function syncPersistentMenuFromInstagram(InstagramConnection $connection): array
    {
        $fbMenu = $this->getPersistentMenu($connection);
        $menuItems = [];
        if ($fbMenu !== null) {
            foreach ($fbMenu as $cta) {
                $item = [
                    'title' => $cta['title'] ?? '',
                    'type' => $cta['type'] ?? 'postback'
                ];
                if ($item['type'] === 'postback') {
                    $item['payload'] = $cta['payload'] ?? '';
                } else {
                    $item['url'] = $cta['url'] ?? '';
                }
                $menuItems[] = $item;
            }
        }

        // Save menuItems to connection's settings file
        $currentSettings = $connection->getBotSettings() ?: [];
        $currentSettings['persistent-menu'] = $menuItems;
        $connection->setBotSettings($currentSettings);
        $this->entityManager->flush();

        return $menuItems;
    }

    /**
     * Retrieve the welcome screen (greeting and get started button) settings from Instagram.
     */
    public function getWelcomeScreenSettings(InstagramConnection $connection): ?array
    {
        $accessToken = $this->getAccessToken($connection);
        $url = "https://graph.facebook.com/v21.0/me/messenger_profile";

        $response = $this->httpClient->request('GET', $url, [
            'headers' => [
                'Authorization' => "Bearer {$accessToken}",
            ],
            'query' => [
                'fields' => 'greeting,get_started,ice_breakers',
                'platform' => 'instagram'
            ]
        ]);

        $content = $response->toArray(false);
        if ($response->getStatusCode() >= 400) {
            throw new \RuntimeException($content['error']['message'] ?? 'Failed to retrieve welcome screen settings from Instagram.');
        }

        return $content['data'][0] ?? [];
    }

    /**
     * Update the welcome screen settings on Instagram.
     */
    public function setWelcomeScreenSettings(InstagramConnection $connection, array $settings): array
    {
        $accessToken = $this->getAccessToken($connection);
        $url = "https://graph.facebook.com/v21.0/me/messenger_profile";

        $payload = [];
        $deleteFields = [];

        $iceBreakersStatus = $settings['iceBreakersStatus'] ?? 'disabled';
        $iceBreakers = $settings['iceBreakers'] ?? [];

        if ($iceBreakersStatus === 'enabled' && !empty($iceBreakers)) {
            $ctas = [];
            foreach ($iceBreakers as $faq) {
                $question = trim($faq['question'] ?? '');
                $faqPayload = trim($faq['payload'] ?? '');
                if ($question !== '' && $faqPayload !== '') {
                    $ctas[] = [
                        'question' => substr($question, 0, 80), // Instagram limit is 80 chars
                        'payload' => $faqPayload
                    ];
                }
            }
            if (!empty($ctas)) {
                $payload['ice_breakers'] = [
                    [
                        'locale' => 'default',
                        'call_to_actions' => $ctas
                    ]
                ];
            } else {
                $deleteFields[] = 'ice_breakers';
            }
        } else {
            $deleteFields[] = 'ice_breakers';
        }

        $results = [];

        if (!empty($deleteFields)) {
            try {
                $response = $this->httpClient->request('DELETE', $url, [
                    'headers' => [
                        'Authorization' => "Bearer {$accessToken}",
                        'Content-Type' => 'application/json',
                    ],
                    'query' => [
                        'platform' => 'instagram'
                    ],
                    'json' => [
                        'fields' => $deleteFields
                    ]
                ]);
                $results['delete'] = $response->toArray(false);
            } catch (\Exception $e) {
                // Silently ignore deletion errors.
            }
        }

        if (!empty($payload)) {
            $payload['platform'] = 'instagram'; // Must specify platform for Instagram updates
            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Authorization' => "Bearer {$accessToken}",
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload
            ]);
            $content = $response->toArray(false);
            if ($response->getStatusCode() >= 400) {
                throw new \RuntimeException($content['error']['message'] ?? 'Failed to update welcome settings on Instagram.');
            }
            $results['post'] = $content;
        }

        return $results;
    }

    /**
     * Sync the welcome screen settings from Instagram page messenger profile back to OpenSquadron connection settings.
     */
    public function syncWelcomeScreenFromInstagram(InstagramConnection $connection): array
    {
        $fbData = $this->getWelcomeScreenSettings($connection);

        // Load existing settings first to preserve custom/draft fields (e.g. disabled greeting text)
        $currentSettings = $connection->getBotSettings() ?: [];
        $localWelcome = $currentSettings['welcome-screen'] ?? [];

        $iceBreakersStatus = 'disabled';
        $iceBreakers = $localWelcome['iceBreakers'] ?? [];

        if ($fbData !== null && !empty($fbData)) {
            if (isset($fbData['ice_breakers'][0]['call_to_actions'])) {
                $iceBreakersStatus = 'enabled';
                $iceBreakers = [];
                foreach ($fbData['ice_breakers'][0]['call_to_actions'] as $cta) {
                    $iceBreakers[] = [
                        'question' => $cta['question'] ?? '',
                        'payload' => $cta['payload'] ?? ''
                    ];
                }
            } else {
                $iceBreakersStatus = 'disabled';
            }
        } else {
            // Instagram returned totally empty profile, meaning nothing is active.
            // Disable all toggles but keep the draft text/payload variables intact.
            $iceBreakersStatus = 'disabled';
        }

        $welcomeSettings = [
            'iceBreakersStatus' => $iceBreakersStatus,
            'iceBreakers' => $iceBreakers
        ];

        $currentSettings['welcome-screen'] = $welcomeSettings;
        $connection->setBotSettings($currentSettings);
        $this->entityManager->flush();

        return $welcomeSettings;
    }

    private function base64UrlDecode(string $input): string
    {
        $remainder = strlen($input) % 4;
        if ($remainder) {
            $padlen = 4 - $remainder;
            $input .= str_repeat('=', $padlen);
        }
        return base64_decode(strtr($input, '-_', '+/'));
    }
}


