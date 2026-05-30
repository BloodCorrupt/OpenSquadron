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
        ?int $existingId = null,
        bool $isSmbOnboarding = false
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

        $settings = $connection->getBotSettings() ?? [];
        if ($isSmbOnboarding) {
            $settings['is_smb'] = true;
        } else {
            unset($settings['is_smb']);
        }
        $connection->setBotSettings($settings);

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
        ?string $phoneNumber,
        bool $isSmbOnboarding = false
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

        $settings = $connection->getBotSettings() ?? [];
        if ($isSmbOnboarding) {
            $settings['is_smb'] = true;
        } else {
            unset($settings['is_smb']);
        }
        $connection->setBotSettings($settings);

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
    public function syncEmbeddedSignupConnections(string $oauthCode, string $appId, string $appSecret, int $limitSlots = 999, ?string $currentUrl = null, ?string $systemUserToken = null): array
    {
        // 1. Exchange code for access token
        $query = [
            'client_id' => $appId,
            'client_secret' => $appSecret,
            'code' => $oauthCode,
        ];
        if ($currentUrl) {
            $query['redirect_uri'] = $currentUrl;
        }

        $response = $this->httpClient->request('GET', 'https://graph.facebook.com/v21.0/oauth/access_token', [
            'query' => $query
        ]);
        
        if ($response->getStatusCode() >= 400) {
            $data = $response->toArray(false);
            throw new \RuntimeException('Failed to exchange code: ' . ($data['error']['message'] ?? 'Unknown error'));
        }

        $tokenData = $response->toArray();
        $userAccessToken = $tokenData['access_token'];

        // Use the System User Token for stored connections (long-lived), fall back to user token
        $tokenForStorage = $systemUserToken ?: $userAccessToken;
        // Use the user token for discovery (it has the user's permissions to list their WABAs)
        $discoveryToken = $userAccessToken;

        // 2. Fetch WABAs owned or shared via this token
        $wabas = [];

        // Try querying owned accounts (whatsapp_business_accounts)
        try {
            $ownedResponse = $this->httpClient->request('GET', 'https://graph.facebook.com/v21.0/me/whatsapp_business_accounts', [
                'headers' => [
                    'Authorization' => "Bearer {$discoveryToken}"
                ]
            ]);
            if ($ownedResponse->getStatusCode() === 200) {
                $ownedData = $ownedResponse->toArray();
                if (!empty($ownedData['data'])) {
                    $wabas = array_merge($wabas, $ownedData['data']);
                }
            }
        } catch (\Exception $e) {
            // Silently ignore owned fetch failure
        }

        // Try querying client/shared accounts (client_whatsapp_business_accounts)
        try {
            $clientResponse = $this->httpClient->request('GET', 'https://graph.facebook.com/v21.0/me/client_whatsapp_business_accounts', [
                'headers' => [
                    'Authorization' => "Bearer {$discoveryToken}"
                ]
            ]);
            if ($clientResponse->getStatusCode() === 200) {
                $clientData = $clientResponse->toArray();
                if (!empty($clientData['data'])) {
                    $existingIds = array_column($wabas, 'id');
                    foreach ($clientData['data'] as $waba) {
                        if (!in_array($waba['id'], $existingIds, true)) {
                            $wabas[] = $waba;
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            // Silently ignore client fetch failure
        }

        // Also try querying the user's businesses for shared WABAs
        if (empty($wabas)) {
            try {
                $bizResponse = $this->httpClient->request('GET', 'https://graph.facebook.com/v21.0/me/businesses', [
                    'headers' => [
                        'Authorization' => "Bearer {$discoveryToken}"
                    ],
                    'query' => ['fields' => 'id,name']
                ]);
                if ($bizResponse->getStatusCode() === 200) {
                    $bizData = $bizResponse->toArray();
                    foreach (($bizData['data'] ?? []) as $biz) {
                        try {
                            $bizWabaResp = $this->httpClient->request('GET', "https://graph.facebook.com/v21.0/{$biz['id']}/owned_whatsapp_business_accounts", [
                                'headers' => [
                                    'Authorization' => "Bearer {$discoveryToken}"
                                ]
                            ]);
                            if ($bizWabaResp->getStatusCode() === 200) {
                                $bizWabaData = $bizWabaResp->toArray();
                                $existingIds = array_column($wabas, 'id');
                                foreach (($bizWabaData['data'] ?? []) as $waba) {
                                    if (!in_array($waba['id'], $existingIds, true)) {
                                        $wabas[] = $waba;
                                    }
                                }
                            }
                        } catch (\Exception $e) {
                            // Continue to next business
                        }
                    }
                }
            } catch (\Exception $e) {
                // Silently ignore businesses fetch failure
            }
        }

        if (empty($wabas)) {
            throw new \RuntimeException('No WhatsApp Business Accounts found. Please ensure you selected a business account during setup.');
        }

        $syncedConnections = [];

        foreach ($wabas as $waba) {
            $wabaId = $waba['id'];

            // Subscribe the App to the WABA's webhooks
            try {
                $this->httpClient->request('POST', "https://graph.facebook.com/v21.0/{$wabaId}/subscribed_apps", [
                    'headers' => [
                        'Authorization' => "Bearer {$tokenForStorage}"
                    ]
                ]);
            } catch (\Exception $e) {
                // Silently continue
            }
            
            // Get phone numbers for this WABA with explicit fields
            $phonesResponse = $this->httpClient->request('GET', "https://graph.facebook.com/v21.0/{$wabaId}/phone_numbers", [
                'headers' => [
                    'Authorization' => "Bearer {$discoveryToken}"
                ],
                'query' => [
                    'fields' => 'id,display_phone_number,verified_name,quality_rating'
                ]
            ]);

            $phonesData = $phonesResponse->toArray(false);
            $phones = $phonesData['data'] ?? [];

            foreach ($phones as $phone) {
                $phoneNumberId = $phone['id'];
                $displayPhoneNumber = $phone['display_phone_number'] ?? null;
                $verifiedName = $phone['verified_name'] ?? null;

                // If verified_name is missing, try fetching it directly
                if (empty($verifiedName) && $phoneNumberId) {
                    try {
                        $directPhoneResp = $this->httpClient->request('GET', "https://graph.facebook.com/v21.0/{$phoneNumberId}", [
                            'query' => [
                                'access_token' => $discoveryToken,
                                'fields' => 'id,display_phone_number,verified_name,quality_rating'
                            ]
                        ]);
                        if ($directPhoneResp->getStatusCode() === 200) {
                            $directData = $directPhoneResp->toArray();
                            $verifiedName = $directData['verified_name'] ?? $verifiedName;
                            $displayPhoneNumber = $directData['display_phone_number'] ?? $displayPhoneNumber;
                        }
                    } catch (\Exception $e) {
                        // Use whatever we have
                    }
                }

                $verifiedName = $verifiedName ?: ($displayPhoneNumber ?: 'WhatsApp Connection');

                $existing = $this->getConnectionByPhoneNumberId($phoneNumberId);
                if ($existing) {
                    $conn = $this->updateConnection($existing->getId(), $wabaId, $tokenForStorage, $phoneNumberId, $verifiedName, $displayPhoneNumber);
                } else {
                    $conn = $this->saveConnection($wabaId, $tokenForStorage, $phoneNumberId, $verifiedName, $displayPhoneNumber);
                }
                $syncedConnections[] = ['id' => $conn->getId(), 'name' => $verifiedName];
            }
        }

        return $syncedConnections;
    }

    /**
     * Sync a connection from the WhatsApp Business App (Coexistence) onboarding flow.
     * 
     * Per Meta docs:
     * 1. Exchange code for user token (NO redirect_uri — code comes from FB.login SDK)
     * 2. Use /debug_token to discover WABAs from granular_scopes
     * 3. Use System User Token for stored connections (long-lived)
     * 4. Subscribe WABA to webhooks
     */
    /**
     * Write a diagnostic line to var/wa_onboarding_debug.log
     */
    private function debugLog(string $msg): void
    {
        $logFile = dirname(__DIR__, 2) . '/var/wa_onboarding_debug.log';
        $line = date('Y-m-d H:i:s') . ' - ' . $msg . "\n";
        file_put_contents($logFile, $line, FILE_APPEND);
    }

    public function syncBusinessAppOnboarding(
        string $oauthCode,
        string $appId,
        string $appSecret,
        string $systemUserToken,
        ?string $hintWabaId = null,
        ?string $hintPhoneNumberId = null
    ): array {
        $this->debugLog("=== syncBusinessAppOnboarding START ===");
        $this->debugLog("hintWabaId={$hintWabaId}, hintPhoneNumberId={$hintPhoneNumberId}");

        // ── FAST PATH: If frontend gave us both waba_id + phone_number_id, skip all discovery ──
        // This is the most reliable path — the WA_EMBEDDED_SIGNUP event already told us exactly
        // which phone was selected. Just fetch its details and save it.
        if ($hintWabaId && $hintPhoneNumberId) {
            $this->debugLog("FAST PATH: have both hintWabaId and hintPhoneNumberId, skipping code exchange & discovery");

            // Still exchange the code to validate the session (but we don't strictly need the token)
            try {
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
                $this->debugLog("FAST PATH: code exchange OK");
            } catch (\Exception $e) {
                $this->debugLog("FAST PATH: code exchange failed: " . $e->getMessage());
                throw $e;
            }

            // Subscribe the app to the WABA
            try {
                $this->httpClient->request('POST', "https://graph.facebook.com/v21.0/{$hintWabaId}/subscribed_apps", [
                    'headers' => ['Authorization' => "Bearer {$systemUserToken}"]
                ]);
                $this->debugLog("FAST PATH: subscribed_apps OK");
            } catch (\Exception $e) {
                $this->debugLog("FAST PATH: subscribed_apps failed: " . $e->getMessage());
            }

            // Fetch phone details directly using system user token
            $phoneResp = $this->httpClient->request('GET', "https://graph.facebook.com/v21.0/{$hintPhoneNumberId}", [
                'query' => [
                    'access_token' => $systemUserToken,
                    'fields' => 'id,display_phone_number,verified_name,quality_rating,platform_type'
                ]
            ]);
            $phoneData = $phoneResp->toArray(false);
            $this->debugLog("FAST PATH: phone fetch status=" . $phoneResp->getStatusCode() . " data=" . json_encode($phoneData));

            if ($phoneResp->getStatusCode() >= 400) {
                throw new \RuntimeException('Failed to fetch phone details: ' . ($phoneData['error']['message'] ?? 'Unknown error'));
            }

            $displayPhoneNumber = $phoneData['display_phone_number'] ?? null;
            $verifiedName = $phoneData['verified_name'] ?? $displayPhoneNumber ?? 'WhatsApp Connection';
            $platformType = $phoneData['platform_type'] ?? 'NOT_APPLICABLE';
            $isSmb = ($platformType !== 'CLOUD_API');

            $this->debugLog("FAST PATH: phone={$displayPhoneNumber}, name={$verifiedName}, platform={$platformType}, isSmb=" . ($isSmb ? 'true' : 'false'));

            $existing = $this->getConnectionByPhoneNumberId($hintPhoneNumberId);
            $isNew = !$existing;

            if ($existing) {
                $existingSettings = $existing->getBotSettings() ?? [];
                $isSmb = $existingSettings['is_smb'] ?? $isSmb;
                $conn = $this->updateConnection($existing->getId(), $hintWabaId, $systemUserToken, $hintPhoneNumberId, $verifiedName, $displayPhoneNumber, $isSmb);
                $this->debugLog("FAST PATH: updated existing connection id=" . $conn->getId());
            } else {
                $conn = $this->saveConnection($hintWabaId, $systemUserToken, $hintPhoneNumberId, $verifiedName, $displayPhoneNumber, null, $isSmb);
                $this->debugLog("FAST PATH: created new connection id=" . $conn->getId());
            }

            $needsRegistration = $isNew && !$isSmb;
            $this->debugLog("FAST PATH: needsRegistration=" . ($needsRegistration ? 'true' : 'false'));
            $this->debugLog("=== syncBusinessAppOnboarding END (FAST PATH) ===");

            return [[
                'id' => $conn->getId(),
                'name' => $verifiedName,
                'phone' => $displayPhoneNumber,
                'needsRegistration' => $needsRegistration,
                'isSmb' => $isSmb
            ]];
        }

        // ── STANDARD PATH: no hint IDs from frontend, need to discover via code exchange + /debug_token ──
        $this->debugLog("STANDARD PATH: exchanging code...");

        // 1. Exchange code for user access token — NO redirect_uri (FB.login uses xd_arbiter)
        $response = $this->httpClient->request('GET', 'https://graph.facebook.com/v21.0/oauth/access_token', [
            'query' => [
                'client_id' => $appId,
                'client_secret' => $appSecret,
                'code' => $oauthCode,
            ]
        ]);

        if ($response->getStatusCode() >= 400) {
            $data = $response->toArray(false);
            $this->debugLog("STANDARD PATH: code exchange FAILED: " . json_encode($data));
            throw new \RuntimeException('Failed to exchange code: ' . ($data['error']['message'] ?? 'Unknown error'));
        }

        $tokenData = $response->toArray();
        $userAccessToken = $tokenData['access_token'];
        $this->debugLog("STANDARD PATH: code exchange OK, got user token");

        // 2. Discover WABAs via /debug_token
        // IMPORTANT: Both whatsapp_business_management AND whatsapp_business_messaging 
        // target_ids are WABA IDs (NOT phone number IDs)
        $wabaIds = [];

        try {
            $appAccessToken = "{$appId}|{$appSecret}";
            $debugResponse = $this->httpClient->request('GET', 'https://graph.facebook.com/v21.0/debug_token', [
                'query' => ['input_token' => $userAccessToken],
                'headers' => ['Authorization' => "Bearer {$appAccessToken}"]
            ]);
            $debugRaw = $debugResponse->toArray(false);
            $this->debugLog("STANDARD PATH: /debug_token status=" . $debugResponse->getStatusCode() . " data=" . json_encode($debugRaw));

            if ($debugResponse->getStatusCode() === 200) {
                $granularScopes = $debugRaw['data']['granular_scopes'] ?? [];
                foreach ($granularScopes as $scope) {
                    // Both scopes return WABA IDs in target_ids
                    if (in_array($scope['scope'], ['whatsapp_business_management', 'whatsapp_business_messaging']) && !empty($scope['target_ids'])) {
                        $wabaIds = array_merge($wabaIds, $scope['target_ids']);
                    }
                }
            }
        } catch (\Exception $e) {
            $this->debugLog("STANDARD PATH: /debug_token exception: " . $e->getMessage());
        }

        $this->debugLog("STANDARD PATH: after /debug_token => wabaIds=" . json_encode(array_unique($wabaIds)));

        // 3. Fallback: use hint waba_id
        if (empty($wabaIds) && $hintWabaId) {
            $wabaIds[] = $hintWabaId;
            $this->debugLog("STANDARD PATH: fallback to hintWabaId={$hintWabaId}");
        }

        // 4. Fallback: query /me/whatsapp_business_accounts with user token
        if (empty($wabaIds)) {
            try {
                $ownedResponse = $this->httpClient->request('GET', 'https://graph.facebook.com/v21.0/me/whatsapp_business_accounts', [
                    'headers' => ['Authorization' => "Bearer {$userAccessToken}"]
                ]);
                $ownedRaw = $ownedResponse->toArray(false);
                $this->debugLog("STANDARD PATH: /me/whatsapp_business_accounts status=" . $ownedResponse->getStatusCode() . " data=" . json_encode($ownedRaw));
                if ($ownedResponse->getStatusCode() === 200) {
                    foreach (($ownedRaw['data'] ?? []) as $waba) {
                        $wabaIds[] = $waba['id'];
                    }
                }
            } catch (\Exception $e) {
                $this->debugLog("STANDARD PATH: /me/whatsapp_business_accounts exception: " . $e->getMessage());
            }
        }

        if (empty($wabaIds)) {
            $this->debugLog("STANDARD PATH: NO WABAs found at all. ABORTING.");
            throw new \RuntimeException('No WhatsApp Business Accounts found. Please ensure you selected a business account during setup.');
        }

        $wabaIds = array_unique($wabaIds);
        $this->debugLog("STANDARD PATH: final wabaIds=" . json_encode(array_values($wabaIds)));

        $syncedConnections = [];

        foreach ($wabaIds as $wabaId) {
            // Subscribe the App to the WABA
            try {
                $this->httpClient->request('POST', "https://graph.facebook.com/v21.0/{$wabaId}/subscribed_apps", [
                    'headers' => ['Authorization' => "Bearer {$systemUserToken}"]
                ]);
                $this->debugLog("STANDARD PATH: subscribed_apps for waba={$wabaId} OK");
            } catch (\Exception $e) {
                $this->debugLog("STANDARD PATH: subscribed_apps for waba={$wabaId} failed: " . $e->getMessage());
            }


            // ── Fallback: list phone numbers from the WABA edge ──
            // Try system user token first (more reliable), then user token as fallback
            $phones = [];
            foreach ([$systemUserToken, $userAccessToken] as $tryToken) {
                $tokenLabel = ($tryToken === $systemUserToken) ? 'systemUserToken' : 'userAccessToken';
                try {
                    $phonesResponse = $this->httpClient->request('GET', "https://graph.facebook.com/v21.0/{$wabaId}/phone_numbers", [
                        'headers' => ['Authorization' => "Bearer {$tryToken}"],
                        'query' => ['fields' => 'id,display_phone_number,verified_name,quality_rating']
                    ]);
                    $phonesData = $phonesResponse->toArray(false);
                    $this->debugLog("STANDARD PATH: /{$wabaId}/phone_numbers with {$tokenLabel} status=" . $phonesResponse->getStatusCode() . " data=" . json_encode($phonesData));

                    if ($phonesResponse->getStatusCode() === 200 && !empty($phonesData['data'])) {
                        $phones = $phonesData['data'];
                        break; // success, stop trying
                    }
                } catch (\Exception $e) {
                    $this->debugLog("STANDARD PATH: /{$wabaId}/phone_numbers with {$tokenLabel} exception: " . $e->getMessage());
                }
            }

            $this->debugLog("STANDARD PATH: phones count after WABA edge query: " . count($phones));

            foreach ($phones as $phone) {
                $phoneNumberId = $phone['id'];
                $displayPhoneNumber = $phone['display_phone_number'] ?? null;
                $verifiedName = $phone['verified_name'] ?? null;

                // Fetch platform_type individually
                // IMPORTANT: SMB/Business App numbers do NOT return platform_type field.
                // Cloud API numbers return platform_type=CLOUD_API.
                // So if platform_type is absent → it's SMB.
                $platformType = null;
                try {
                    $indivResp = $this->httpClient->request('GET', "https://graph.facebook.com/v21.0/{$phoneNumberId}", [
                        'query' => [
                            'access_token' => $systemUserToken,
                            'fields' => 'id,display_phone_number,verified_name,quality_rating,platform_type'
                        ]
                    ]);
                    $indivData = $indivResp->toArray(false);
                    $this->debugLog("STANDARD PATH: individual phone fetch id={$phoneNumberId} status=" . $indivResp->getStatusCode() . " data=" . json_encode($indivData));

                    if ($indivResp->getStatusCode() === 200) {
                        $verifiedName = $indivData['verified_name'] ?? $verifiedName;
                        $displayPhoneNumber = $indivData['display_phone_number'] ?? $displayPhoneNumber;
                        $platformType = $indivData['platform_type'] ?? null;
                    }
                } catch (\Exception $e) {
                    $this->debugLog("STANDARD PATH: individual phone fetch EXCEPTION for id={$phoneNumberId}: " . $e->getMessage());
                    // If the individual fetch fails entirely, try without platform_type
                    try {
                        $fallbackResp = $this->httpClient->request('GET', "https://graph.facebook.com/v21.0/{$phoneNumberId}", [
                            'query' => [
                                'access_token' => $systemUserToken,
                                'fields' => 'id,display_phone_number,verified_name,quality_rating'
                            ]
                        ]);
                        if ($fallbackResp->getStatusCode() === 200) {
                            $fallbackData = $fallbackResp->toArray();
                            $verifiedName = $fallbackData['verified_name'] ?? $verifiedName;
                            $displayPhoneNumber = $fallbackData['display_phone_number'] ?? $displayPhoneNumber;
                        }
                    } catch (\Exception $e2) {
                        // Use whatever we have from the edge query
                    }
                }

                $verifiedName = $verifiedName ?: ($displayPhoneNumber ?: 'WhatsApp Connection');

                // If platform_type is explicitly CLOUD_API → Cloud API number
                // If platform_type is absent/null or anything else → SMB/Business App
                $isSmb = ($platformType !== 'CLOUD_API');
                $this->debugLog("STANDARD PATH: phone id={$phoneNumberId} platformType=" . ($platformType ?? 'NULL') . " isSmb=" . ($isSmb ? 'true' : 'false'));

                $existing = $this->getConnectionByPhoneNumberId($phoneNumberId);
                $isNew = !$existing;

                if ($existing) {
                    $existingSettings = $existing->getBotSettings() ?? [];
                    $isSmb = $existingSettings['is_smb'] ?? $isSmb;
                    $conn = $this->updateConnection($existing->getId(), $wabaId, $systemUserToken, $phoneNumberId, $verifiedName, $displayPhoneNumber, $isSmb);
                } else {
                    $conn = $this->saveConnection($wabaId, $systemUserToken, $phoneNumberId, $verifiedName, $displayPhoneNumber, null, $isSmb);
                }

                $needsRegistration = $isNew && !$isSmb;
                $syncedConnections[$conn->getId()] = [
                    'id' => $conn->getId(),
                    'name' => $verifiedName,
                    'phone' => $displayPhoneNumber,
                    'needsRegistration' => $needsRegistration,
                    'isSmb' => $isSmb
                ];
                $this->debugLog("STANDARD PATH: saved phone id={$phoneNumberId} connId={$conn->getId()} isSmb=" . ($isSmb ? 'true' : 'false') . " needsReg=" . ($needsRegistration ? 'true' : 'false'));
            }
        }

        $this->debugLog("STANDARD PATH: total synced connections: " . count($syncedConnections));
        $this->debugLog("=== syncBusinessAppOnboarding END ===");

        return array_values($syncedConnections);
    }

    /**
     * Sync a connection using data captured from the WA_EMBEDDED_SIGNUP message event.
     * Uses the System User Access Token from MetaSetting instead of exchanging an OAuth code.
     */
    public function syncFromEmbeddedSignupEvent(string $wabaId, string $phoneNumberId, string $systemUserToken, int $limitSlots = 999): array
    {
        $phoneUrl = "https://graph.facebook.com/v21.0/{$phoneNumberId}";
        $displayPhoneNumber = null;
        $verifiedName = 'WhatsApp Connection';
        $platformType = null;

        try {
            $phoneResponse = $this->httpClient->request('GET', $phoneUrl, [
                'query' => [
                    'access_token' => $systemUserToken,
                    'fields' => 'id,display_phone_number,verified_name,quality_rating,platform_type'
                ]
            ]);

            $phoneData = $phoneResponse->toArray(false);
            if ($phoneResponse->getStatusCode() === 200) {
                $displayPhoneNumber = $phoneData['display_phone_number'] ?? null;
                $verifiedName = $phoneData['verified_name'] ?? $displayPhoneNumber ?? 'WhatsApp Connection';
                $platformType = $phoneData['platform_type'] ?? null;
            } else {
                throw new \RuntimeException('Failed status: ' . $phoneResponse->getStatusCode());
            }
        } catch (\Exception $e) {
            // Fallback: try fetching without platform_type (helpful for some SMB numbers)
            try {
                $fallbackResponse = $this->httpClient->request('GET', $phoneUrl, [
                    'query' => [
                        'access_token' => $systemUserToken,
                        'fields' => 'id,display_phone_number,verified_name,quality_rating'
                    ]
                ]);
                $phoneData = $fallbackResponse->toArray(false);
                if ($fallbackResponse->getStatusCode() === 200) {
                    $displayPhoneNumber = $phoneData['display_phone_number'] ?? null;
                    $verifiedName = $phoneData['verified_name'] ?? $displayPhoneNumber ?? 'WhatsApp Connection';
                } else {
                    throw new \RuntimeException('Failed status: ' . $fallbackResponse->getStatusCode());
                }
            } catch (\Exception $fallbackEx) {
                throw new \RuntimeException('Failed to fetch phone number details: ' . $e->getMessage());
            }
        }

        $isSmb = ($platformType !== 'CLOUD_API');

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
        $isNew = !$existing;

        $syncedConnections = [];

        if ($existing) {
            $existingSettings = $existing->getBotSettings() ?? [];
            $isSmb = $existingSettings['is_smb'] ?? $isSmb;
            // Update existing connection
            $conn = $this->updateConnection(
                $existing->getId(),
                $wabaId,
                $systemUserToken,
                $phoneNumberId,
                $verifiedName,
                $displayPhoneNumber,
                $isSmb
            );
        } else {
            // Create new connection
            $conn = $this->saveConnection(
                $wabaId,
                $systemUserToken,
                $phoneNumberId,
                $verifiedName,
                $displayPhoneNumber,
                null,
                $isSmb
            );
        }

        $needsRegistration = $isNew && !$isSmb;

        $syncedConnections[] = [
            'id' => $conn->getId(),
            'name' => $verifiedName,
            'phone' => $displayPhoneNumber,
            'needsRegistration' => $needsRegistration,
            'isSmb' => $isSmb
        ];

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
