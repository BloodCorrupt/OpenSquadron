<?php

namespace App\Controller;

use App\Service\WhatsAppConnectionService;
use App\Service\SubscriptionUsageService;
use App\Entity\Admin;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class ConnectionSetupController extends AbstractController
{
    public function __construct(
        private WhatsAppConnectionService $whatsappService,
        private SubscriptionUsageService $usageService,
        private \App\Service\FacebookService $facebookService
    ) {
    }

    #[Route('/whatsapp-business/connect', name: 'whatsapp_connect_show', methods: ['GET'])]
    public function show(Request $request): Response
    {
        $oauthCode = $request->query->get('code');
        if ($oauthCode) {
            // Fallback: if Meta redirects the browser directly with ?code= (edge case).
            // Primary flow is via AJAX through /business-app-callback endpoint.
            try {
                /** @var Admin $user */
                $user = $this->getUser();
                $metaSetting = $this->facebookService->getEffectiveSetting();
                
                if ($metaSetting) {
                    $appId = $metaSetting->getWhatsappAppId() ?: $metaSetting->getAppId();
                    $encryptedSecret = $metaSetting->getWhatsappEncryptedAppSecret() ?: $metaSetting->getEncryptedAppSecret();
                    $appSecret = $encryptedSecret ? $this->facebookService->decryptToken($encryptedSecret) : null;
                    $systemUserToken = $metaSetting->getSystemUserAccessToken();

                    if ($appId && $appSecret && $systemUserToken) {
                        if ($this->usageService->canAddBot($user)) {
                            $syncedNames = $this->whatsappService->syncBusinessAppOnboarding(
                                $oauthCode, $appId, $appSecret, $systemUserToken
                            );
                            if (!empty($syncedNames)) {
                                $names = implode(', ', array_column($syncedNames, 'name'));
                                $this->addFlash('success', 'Connected ' . count($syncedNames) . ' phone number(s): ' . $names);
                            } else {
                                $this->addFlash('error', 'No valid phone numbers found.');
                            }
                        } else {
                            $this->addFlash('error', 'Bot connection limit reached.');
                        }
                    }
                }
            } catch (\Exception $e) {
                $this->addFlash('error', $e->getMessage());
            }
            
            return $this->redirectToRoute('whatsapp_connect_show');
        }

        $connections = $this->whatsappService->getAllConnections();
        $metaSetting = $this->facebookService->getEffectiveSetting();

        return $this->render('whatsapp/connect.html.twig', [
            'connections' => $connections,
            'metaSetting' => $metaSetting,
        ]);
    }

    #[Route('/whatsapp-business/connect', name: 'whatsapp_connect', methods: ['POST'])]
    public function connect(Request $request): Response
    {
        $businessAccountId = trim($request->request->get('businessAccountId', ''));
        $accessToken = trim($request->request->get('accessToken', ''));
        $phoneNumberId = trim($request->request->get('phoneNumberId', ''));
        $label = trim($request->request->get('label', ''));
        $phoneNumber = trim($request->request->get('phoneNumber', ''));

        /** @var Admin $user */
        $user = $this->getUser();

        // Check bot limit
        if (!$this->usageService->canAddBot($user)) {
            $usage = $this->usageService->getBotUsage($user);
            $this->addFlash('error', sprintf(
                'Bot connection limit reached (%d/%d). Upgrade your subscription to connect more bots.',
                $usage['current'],
                $usage['limit']
            ));
            return $this->redirectToRoute('whatsapp_connect_show');
        }

        if (empty($businessAccountId) || empty($accessToken) || empty($phoneNumberId)) {
            $this->addFlash('error', 'Business Account ID, Access Token, and Phone Number ID are required.');
            return $this->redirectToRoute('whatsapp_connect_show');
        }

        // Validate with Meta API
        $validationResult = $this->whatsappService->validateWithMetaApi($businessAccountId, $accessToken);

        if (!$validationResult['success']) {
            $this->addFlash('error', 'Failed to validate with Meta API: ' . $validationResult['error']);
            return $this->redirectToRoute('whatsapp_connect_show');
        }

        try {
            $this->whatsappService->saveConnection(
                $businessAccountId,
                $accessToken,
                $phoneNumberId,
                $label ?: null,
                $phoneNumber ?: null
            );
            $this->addFlash('success', 'WhatsApp Connection saved and validated successfully!');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to save connection: ' . $e->getMessage());
        }

        return $this->redirectToRoute('whatsapp_connect_show');
    }

    #[Route('/whatsapp-business/connect/{id}/edit', name: 'whatsapp_connect_edit', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function edit(int $id): Response
    {
        $connection = $this->whatsappService->getConnectionById($id);
        if (!$connection) {
            $this->addFlash('error', 'Connection not found.');
            return $this->redirectToRoute('whatsapp_connect_show');
        }

        $connections = $this->whatsappService->getAllConnections();
        $metaSetting = $this->facebookService->getEffectiveSetting();

        return $this->render('whatsapp/connect.html.twig', [
            'connections' => $connections,
            'editConnection' => $connection,
            'metaSetting' => $metaSetting,
        ]);
    }

    #[Route('/whatsapp-business/connect/{id}/update', name: 'whatsapp_connect_update', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function update(int $id, Request $request): Response
    {
        $businessAccountId = trim($request->request->get('businessAccountId', ''));
        $accessToken = trim($request->request->get('accessToken', ''));
        $phoneNumberId = trim($request->request->get('phoneNumberId', ''));
        $label = trim($request->request->get('label', ''));
        $phoneNumber = trim($request->request->get('phoneNumber', ''));

        if (empty($businessAccountId) || empty($phoneNumberId)) {
            $this->addFlash('error', 'Business Account ID and Phone Number ID are required.');
            return $this->redirectToRoute('whatsapp_connect_edit', ['id' => $id]);
        }

        // If a new access token is provided, validate it
        if (!empty($accessToken)) {
            $validationResult = $this->whatsappService->validateWithMetaApi($businessAccountId, $accessToken);
            if (!$validationResult['success']) {
                $this->addFlash('error', 'Failed to validate with Meta API: ' . $validationResult['error']);
                return $this->redirectToRoute('whatsapp_connect_edit', ['id' => $id]);
            }
        }

        try {
            $result = $this->whatsappService->updateConnection(
                $id,
                $businessAccountId,
                $accessToken ?: null,
                $phoneNumberId,
                $label ?: null,
                $phoneNumber ?: null
            );

            if (!$result) {
                $this->addFlash('error', 'Connection not found.');
            } else {
                $this->addFlash('success', 'Connection updated successfully!');
            }
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to update connection: ' . $e->getMessage());
        }

        return $this->redirectToRoute('whatsapp_connect_show');
    }

    #[Route('/whatsapp-business/connect/{id}/delete', name: 'whatsapp_connect_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id): JsonResponse
    {
        try {
            $success = $this->whatsappService->deleteConnection($id);
            return new JsonResponse(['success' => $success]);
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    #[Route('/whatsapp-business/connect/{id}/register', name: 'whatsapp_connect_register', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function registerConnection(int $id, Request $request): JsonResponse
    {
        $pin = $request->request->get('pin');
        if (!$pin || !preg_match('/^\d{6}$/', $pin)) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid PIN. Must be 6 digits.']);
        }

        try {
            $this->whatsappService->registerPhoneNumber($id, $pin);
            return new JsonResponse(['success' => true]);
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()]);
        }
    }
    #[Route('/whatsapp/connect/{id}/toggle-status', name: 'whatsapp_connect_toggle_status', methods: ['POST'])]
    public function toggleStatus(int $id, \Doctrine\ORM\EntityManagerInterface $em): Response
    {
        try {
            $connection = $this->whatsappService->getConnectionById($id);
            if (!$connection) {
                $this->addFlash('error', 'Connection not found.');
                return $this->redirectToRoute('whatsapp_connect_show');
            }

            $newStatus = $connection->getStatus() === 'active' ? 'inactive' : 'active';
            $connection->setStatus($newStatus);
            $em->flush();

            $this->addFlash('success', sprintf('Connection successfully %s!', $newStatus === 'active' ? 'enabled' : 'disabled'));
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to toggle status: ' . $e->getMessage());
        }

        return $this->redirectToRoute('whatsapp_connect_show');
    }

    #[Route('/whatsapp-business/connect/embedded-callback', name: 'whatsapp_connect_embedded_callback', methods: ['POST'])]
    public function whatsappEmbeddedCallback(Request $request): Response
    {
        // The embedded signup flow now passes wabaId and phoneNumberId directly via the message event
        // We no longer rely on the OAuth code.

        try {
            /** @var Admin $user */
            $user = $this->getUser();
            
            $metaSetting = $this->facebookService->getEffectiveSetting();
            if (!$metaSetting || !$metaSetting->getAppId() || !$metaSetting->getEncryptedAppSecret()) {
                return $this->json(['success' => false, 'error' => 'Meta API credentials are not configured in settings.']);
            }
            
            $appId = $metaSetting->getAppId();
            $appSecret = $this->facebookService->decryptToken($metaSetting->getEncryptedAppSecret());

            // Check if they are allowed to add bots
            if (!$this->usageService->canAddBot($user)) {
                $usage = $this->usageService->getBotUsage($user);
                return $this->json(['success' => false, 'error' => sprintf('Bot connection limit reached (%d/%d). Upgrade your subscription.', $usage['current'], $usage['limit'])]);
            }

            // Calculate slots for the background sync loop (to avoid exceeding quota if Meta returns 10 phones at once)
            $usage = $this->usageService->getBotUsage($user);
            if (in_array($user->getAccountType(), ['super_admin', 'admin'], true) || $usage['limit'] === 0) {
                $availableSlots = 9999; // Unlimited
            } else {
                $availableSlots = max(1, $usage['limit'] - $usage['current']);
            }

            $wabaId = $request->request->get('wabaId');
            $phoneNumberId = $request->request->get('phoneNumberId');

            if (!$wabaId || !$phoneNumberId) {
                return $this->json(['success' => false, 'error' => 'WABA ID and Phone Number ID are required.']);
            }

            if (!$metaSetting->getSystemUserAccessToken()) {
                 return $this->json(['success' => false, 'error' => 'System User Access Token is not configured in settings. Please generate one in the Meta App Dashboard and save it in OpenSquadron settings.']);
            }
            
            $systemUserToken = $metaSetting->getSystemUserAccessToken();

            $syncedNames = $this->whatsappService->syncFromEmbeddedSignupEvent($wabaId, $phoneNumberId, $systemUserToken, $availableSlots);
            
            if (empty($syncedNames)) {
                return $this->json(['success' => false, 'error' => 'No valid phone numbers found for the connected Meta account.']);
            }

            $names = array_column($syncedNames, 'name');
            return $this->json([
                'success' => true, 
                'message' => 'Successfully connected ' . count($syncedNames) . ' phone number(s): ' . implode(', ', $names),
                'connections' => $syncedNames
            ]);
            
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * AJAX endpoint for the WhatsApp Business App (Coexistence) onboarding flow.
     * Receives the authorization code from FB.login() callback + optional hint IDs from WA_EMBEDDED_SIGNUP.
     * Exchanges code WITHOUT redirect_uri (since FB.login uses xd_arbiter internally).
     */
    #[Route('/whatsapp-business/connect/business-app-callback', name: 'whatsapp_connect_business_app_callback', methods: ['POST'])]
    public function whatsappBusinessAppCallback(Request $request): Response
    {
        try {
            /** @var Admin $user */
            $user = $this->getUser();

            $metaSetting = $this->facebookService->getEffectiveSetting();
            if (!$metaSetting) {
                return $this->json(['success' => false, 'error' => 'Meta API credentials are not configured in settings.']);
            }

            $appId = $metaSetting->getWhatsappAppId() ?: $metaSetting->getAppId();
            $encryptedSecret = $metaSetting->getWhatsappEncryptedAppSecret() ?: $metaSetting->getEncryptedAppSecret();
            $appSecret = $encryptedSecret ? $this->facebookService->decryptToken($encryptedSecret) : null;

            if (!$appId || !$appSecret) {
                return $this->json(['success' => false, 'error' => 'App ID or App Secret not configured.']);
            }

            $systemUserToken = $metaSetting->getSystemUserAccessToken();
            if (!$systemUserToken) {
                return $this->json(['success' => false, 'error' => 'System User Access Token is not configured. Please add it in Settings.']);
            }

            $code = trim($request->request->get('code', ''));
            if (!$code) {
                return $this->json(['success' => false, 'error' => 'Authorization code is required.']);
            }

            // Check bot limits
            if (!$this->usageService->canAddBot($user)) {
                $usage = $this->usageService->getBotUsage($user);
                return $this->json(['success' => false, 'error' => sprintf('Bot connection limit reached (%d/%d). Upgrade your subscription.', $usage['current'], $usage['limit'])]);
            }

            // Optional hint IDs from the WA_EMBEDDED_SIGNUP frontend event
            $hintWabaId = trim($request->request->get('wabaId', '')) ?: null;
            $hintPhoneNumberId = trim($request->request->get('phoneNumberId', '')) ?: null;

            $syncedNames = $this->whatsappService->syncBusinessAppOnboarding(
                $code,
                $appId,
                $appSecret,
                $systemUserToken,
                $hintWabaId,
                $hintPhoneNumberId
            );

            if (empty($syncedNames)) {
                return $this->json(['success' => false, 'error' => 'No valid phone numbers found for the connected Meta account.']);
            }

            $names = array_column($syncedNames, 'name');
            return $this->json([
                'success' => true,
                'message' => 'Successfully connected ' . count($syncedNames) . ' phone number(s): ' . implode(', ', $names),
                'connections' => array_values($syncedNames)
            ]);

        } catch (\Exception $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()]);
        }
    }
}
