<?php

namespace App\Service;

use App\Entity\FacebookConnection;
use App\Entity\FacebookSetting;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class FacebookService
{
    private const CIPHER_ALGO = 'aes-256-gcm';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private HttpClientInterface $httpClient,
        private RouterInterface $router,
        #[Autowire(env: 'APP_SECRET')]
        private string $appSecret
    ) {
    }

    // ───────────────────────── Connection CRUD ─────────────────────────

    /**
     * Get the active FacebookSetting for the current tenant.
     */
    public function getSetting(): ?FacebookSetting
    {
        return $this->entityManager
            ->getRepository(FacebookSetting::class)
            ->findOneBy([]);
    }

    /**
     * Return ALL Facebook connections ordered newest-first.
     * @return FacebookConnection[]
     */
    public function getAllConnections(): array
    {
        return $this->entityManager
            ->getRepository(FacebookConnection::class)
            ->findBy([], ['id' => 'DESC']);
    }

    /**
     * Return the default (first active) Facebook connection.
     */
    public function getDefaultConnection(): ?FacebookConnection
    {
        return $this->entityManager
            ->getRepository(FacebookConnection::class)
            ->findOneBy(['status' => 'active'], ['id' => 'ASC']);
    }

    public function getConnectionById(int $id): ?FacebookConnection
    {
        return $this->entityManager
            ->getRepository(FacebookConnection::class)
            ->find($id);
    }

    /**
     * Lookup by Facebook Page ID — used for webhook routing.
     */
    public function getConnectionByPageId(string $pageId): ?FacebookConnection
    {
        return $this->entityManager
            ->getRepository(FacebookConnection::class)
            ->findOneBy(['pageId' => $pageId]);
    }

    /**
     * Save a new Facebook connection or update an existing one.
     */
    public function saveConnection(
        string $pageId,
        string $plainPageAccessToken,
        string $appId,
        string $plainAppSecret,
        ?string $pageName = null,
        ?string $label = null,
        ?int $existingId = null
    ): FacebookConnection {
        if (empty($pageId) || empty($plainPageAccessToken) || empty($appId) || empty($plainAppSecret)) {
            throw new \InvalidArgumentException('Page ID, Page Access Token, App ID, and App Secret are required.');
        }

        $connection = $existingId
            ? $this->getConnectionById($existingId)
            : new FacebookConnection();

        if (!$connection) {
            $connection = new FacebookConnection();
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
     * Update an existing Facebook connection.
     */
    public function updateConnection(
        int $id,
        string $pageId,
        ?string $plainPageAccessToken,
        string $appId,
        ?string $plainAppSecret,
        ?string $pageName,
        ?string $label
    ): ?FacebookConnection {
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
        return $this->router->generate('facebook_webhook_verify', [], UrlGeneratorInterface::ABSOLUTE_URL);
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
    private function getAccessToken(?FacebookConnection $connection = null): string
    {
        $conn = $connection ?? $this->getDefaultConnection();
        if ($conn && $conn->getEncryptedPageAccessToken()) {
            return $this->decryptToken($conn->getEncryptedPageAccessToken());
        }
        throw new \RuntimeException('No Facebook connection with a valid access token found.');
    }

    /**
     * Send a text message to a user via the Facebook Send API.
     */
    public function sendMessage(string $psid, string $text, ?FacebookConnection $connection = null): array
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
     * Send an attachment (image/audio/file) to a user via the Facebook Send API.
     */
    public function sendMediaMessage(string $psid, string $type, string $fileUrl, ?FacebookConnection $connection = null): array
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
            throw new \RuntimeException($data['error']['message'] ?? 'Failed to retrieve Facebook pages.');
        }

        return $data['data'];
    }

    /**
     * Subscribe the Facebook Page to the App's webhook events.
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
                    'subscribed_fields' => 'messages,messaging_postbacks',
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

    /**
     * Parses and verifies Facebook's signed_request parameter.
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
    public function publishFeedPost(FacebookConnection $connection, string $message, ?string $link = null): array
    {
        $accessToken = $this->getAccessToken($connection);
        $pageId = $connection->getPageId();
        $url = "https://graph.facebook.com/v21.0/{$pageId}/feed";

        $json = ['message' => $message];
        if ($link !== null && $link !== '') {
            $json['link'] = $link;
        }

        $response = $this->httpClient->request('POST', $url, [
            'headers' => [
                'Authorization' => "Bearer {$accessToken}",
                'Content-Type' => 'application/json',
            ],
            'json' => $json
        ]);

        $content = $response->toArray(false);
        if ($response->getStatusCode() >= 400) {
            throw new \RuntimeException($content['error']['message'] ?? 'Failed to publish feed post.');
        }

        return $content;
    }

    /**
     * Publish a photo post to the page's photos.
     */
    public function publishPhotoPost(FacebookConnection $connection, string $imageUrl, string $message): array
    {
        $accessToken = $this->getAccessToken($connection);
        $pageId = $connection->getPageId();
        $url = "https://graph.facebook.com/v21.0/{$pageId}/photos";

        $response = $this->httpClient->request('POST', $url, [
            'headers' => [
                'Authorization' => "Bearer {$accessToken}",
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'url' => $imageUrl,
                'caption' => $message,
            ]
        ]);

        $content = $response->toArray(false);
        if ($response->getStatusCode() >= 400) {
            throw new \RuntimeException($content['error']['message'] ?? 'Failed to publish photo post.');
        }

        return $content;
    }

    /**
     * Publish a video post to the page's videos.
     */
    public function publishVideoPost(FacebookConnection $connection, string $videoUrl, string $message): array
    {
        $accessToken = $this->getAccessToken($connection);
        $pageId = $connection->getPageId();
        $url = "https://graph.facebook.com/v21.0/{$pageId}/videos";

        $response = $this->httpClient->request('POST', $url, [
            'headers' => [
                'Authorization' => "Bearer {$accessToken}",
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'file_url' => $videoUrl,
                'description' => $message,
            ]
        ]);

        $content = $response->toArray(false);
        if ($response->getStatusCode() >= 400) {
            throw new \RuntimeException($content['error']['message'] ?? 'Failed to publish video post.');
        }

        return $content;
    }

    /**
     * Publish a post with a Call-To-Action (CTA) link.
     */
    public function publishCtaPost(FacebookConnection $connection, string $message, string $link, string $ctaType): array
    {
        $accessToken = $this->getAccessToken($connection);
        $pageId = $connection->getPageId();
        $url = "https://graph.facebook.com/v21.0/{$pageId}/feed";

        $payload = [
            'message' => $message,
            'link' => $link,
        ];

        // Some App Verification scopes restrict organic CTA feed posts, so we handle call_to_action payload
        if ($ctaType !== '' && $ctaType !== 'NONE') {
            $payload['call_to_action'] = [
                'type' => $ctaType,
                'value' => [
                    'link' => $link
                ]
            ];
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
            // Fallback to standard feed post if CTA restrictions apply
            return $this->publishFeedPost($connection, $message, $link);
        }

        return $content;
    }

    /**
     * Publish a carousel-style slide post using multi-photo attachments.
     */
    public function publishCarouselPost(FacebookConnection $connection, string $message, array $slides): array
    {
        $accessToken = $this->getAccessToken($connection);
        $pageId = $connection->getPageId();

        $mediaIds = [];
        foreach ($slides as $index => $slide) {
            $imageUrl = trim($slide['imageUrl'] ?? '');
            if ($imageUrl === '') {
                continue;
            }

            // Upload photo as unpublished
            $url = "https://graph.facebook.com/v21.0/{$pageId}/photos";
            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Authorization' => "Bearer {$accessToken}",
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'url' => $imageUrl,
                    'published' => false,
                    'caption' => $slide['title'] ?? '',
                ]
            ]);

            $data = $response->toArray(false);
            if ($response->getStatusCode() >= 400 || !isset($data['id'])) {
                continue;
            }

            $mediaIds[] = ['media_fbid' => $data['id']];
        }

        if (empty($mediaIds)) {
            // Fallback to simple feed post if no images could be uploaded
            return $this->publishFeedPost($connection, $message);
        }

        // Publish all uploaded unpublished photos as a single feed post
        $feedUrl = "https://graph.facebook.com/v21.0/{$pageId}/feed";
        $response = $this->httpClient->request('POST', $feedUrl, [
            'headers' => [
                'Authorization' => "Bearer {$accessToken}",
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'message' => $message,
                'attached_media' => $mediaIds,
            ]
        ]);

        $content = $response->toArray(false);
        if ($response->getStatusCode() >= 400) {
            throw new \RuntimeException($content['error']['message'] ?? 'Failed to publish multi-photo feed post.');
        }

        return $content;
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

