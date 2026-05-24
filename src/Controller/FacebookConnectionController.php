<?php

namespace App\Controller;

use App\Entity\FacebookSetting;
use App\Service\FacebookService;
use App\Security\Voter\TeamPermissionVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(TeamPermissionVoter::PERM_FACEBOOK_MANAGE)]
class FacebookConnectionController extends AbstractController
{
    public function __construct(
        private FacebookService $facebookService
    ) {
    }

    #[Route('/settings/facebook', name: 'app_facebook_settings', methods: ['GET'])]
    public function facebookSettings(): Response
    {
        $setting = $this->facebookService->getSetting();
        $globalSetting = $this->facebookService->getGlobalSetting();

        return $this->render('facebook/settings.html.twig', [
            'setting' => $setting ?? new FacebookSetting(),
            'hasGlobalSetting' => $globalSetting && $globalSetting->getAppId() ? true : false,
            'isUsingCustom' => $setting && $setting->getAppId() ? true : false,
        ]);
    }

    #[Route('/settings/facebook/save', name: 'app_facebook_settings_save', methods: ['POST'])]
    public function saveFacebookSettings(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $useCustom = filter_var($request->request->get('useCustom', 'false'), FILTER_VALIDATE_BOOLEAN);
        $setting = $this->facebookService->getSetting();

        if (!$useCustom) {
            if ($setting) {
                $setting->setAppId('');
                $setting->setEncryptedAppSecret('');
                $em->flush();
            }
            return new JsonResponse([
                'success' => true,
                'message' => 'Now using Global Server Settings.',
                'verifyToken' => $setting ? $setting->getVerifyToken() : '',
            ]);
        }

        $appId = trim($request->request->get('appId', ''));
        $appSecret = trim($request->request->get('appSecret', ''));

        if (empty($appId)) {
            return new JsonResponse(['success' => false, 'error' => 'App ID is required.'], 400);
        }

        if (!$setting) {
            $setting = new FacebookSetting();
            $setting->setVerifyToken($this->facebookService->generateVerifyToken());
            $em->persist($setting);
        }

        $setting->setAppId($appId);
        
        if ($appSecret !== '') {
            $setting->setEncryptedAppSecret($this->facebookService->encryptToken($appSecret));
        } elseif (!$setting->getEncryptedAppSecret()) {
            return new JsonResponse(['success' => false, 'error' => 'App Secret is required.'], 400);
        }

        $em->flush();

        return new JsonResponse([
            'success' => true,
            'message' => 'Facebook Settings saved successfully.',
            'verifyToken' => $setting->getVerifyToken(),
        ]);
    }

    #[Route('/facebook/connect', name: 'facebook_connect_show', methods: ['GET'])]
    public function show(): Response
    {
        $connections = $this->facebookService->getAllConnections();
        $setting = $this->facebookService->getEffectiveSetting();

        return $this->render('facebook/connect.html.twig', [
            'connections' => $connections,
            'setting' => $setting,
        ]);
    }

    #[Route('/facebook/connect', name: 'facebook_connect', methods: ['POST'])]
    public function connect(Request $request): Response
    {
        $setting = $this->facebookService->getEffectiveSetting();

        if (!$setting || empty($setting->getAppId()) || empty($setting->getEncryptedAppSecret())) {
            $this->addFlash('error', 'Facebook Settings (App ID and App Secret) must be configured under Settings > Facebook Settings first.');
            return $this->redirectToRoute('facebook_connect_show');
        }

        $appId = $setting->getAppId();
        $appSecret = $this->facebookService->decryptToken($setting->getEncryptedAppSecret());
        $label = trim($request->request->get('label', ''));

        $session = $request->getSession();
        $session->set('fb_connect_app_id', $appId);
        $session->set('fb_connect_app_secret', $appSecret);
        $session->set('fb_connect_label', $label);

        $state = bin2hex(random_bytes(16));
        $session->set('fb_connect_state', $state);

        $redirectUri = $this->generateUrl('facebook_connect_callback', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $redirectUri = str_replace('http://', 'https://', $redirectUri);

        // Scopes allowing both Messaging and future full Page/Post Automation
        $scopes = [
            'pages_show_list',
            'pages_read_engagement',
            'pages_manage_engagement',
            'pages_manage_metadata',
            'pages_messaging',
            'pages_manage_posts',
            'pages_read_user_content'
        ];

        $fbAuthUrl = sprintf(
            'https://www.facebook.com/v21.0/dialog/oauth?client_id=%s&redirect_uri=%s&scope=%s&state=%s',
            urlencode($appId),
            urlencode($redirectUri),
            urlencode(implode(',', $scopes)),
            urlencode($state)
        );

        return $this->redirect($fbAuthUrl);
    }

    #[Route('/facebook/callback', name: 'facebook_connect_callback', methods: ['GET'])]
    public function callback(Request $request): Response
    {
        $code = $request->query->get('code');
        $state = $request->query->get('state');
        $session = $request->getSession();

        $savedState = $session->get('fb_connect_state');
        $setting = $this->facebookService->getEffectiveSetting();
        $appId = $session->get('fb_connect_app_id') ?: ($setting ? $setting->getAppId() : null);
        $appSecret = $session->get('fb_connect_app_secret') ?: ($setting ? $this->facebookService->decryptToken($setting->getEncryptedAppSecret()) : null);

        if (!$code || !$state || $state !== $savedState || !$appId || !$appSecret) {
            $this->addFlash('error', 'Invalid request or session expired. Please try connecting again.');
            return $this->redirectToRoute('facebook_connect_show');
        }

        $redirectUri = $this->generateUrl('facebook_connect_callback', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $redirectUri = str_replace('http://', 'https://', $redirectUri);

        try {
            // 1. Exchange authorization code for a short-lived user access token
            $shortLivedToken = $this->facebookService->exchangeCodeForUserToken($appId, $appSecret, $code, $redirectUri);

            // 2. Exchange short-lived token for a long-lived user access token (60 days)
            $longLivedToken = $this->facebookService->getLongLivedUserToken($appId, $appSecret, $shortLivedToken);

            // 3. Fetch list of user's Facebook pages with their page access tokens
            $pages = $this->facebookService->getUserPages($longLivedToken);

            // 4. Store pages in session securely to avoid passing tokens in the query/form
            $pagesSession = [];
            foreach ($pages as $page) {
                $pagesSession[$page['id']] = [
                    'id' => $page['id'],
                    'name' => $page['name'],
                    'access_token' => $page['access_token'],
                    'category' => $page['category'] ?? '',
                ];
            }
            $session->set('fb_pages_list', $pagesSession);

            return $this->render('facebook/select_pages.html.twig', [
                'pages' => $pages,
            ]);

        } catch (\Exception $e) {
            $this->addFlash('error', 'Facebook connection failed: ' . $e->getMessage());
            return $this->redirectToRoute('facebook_connect_show');
        }
    }

    #[Route('/facebook/connect-page', name: 'facebook_connect_page_submit', methods: ['POST'])]
    public function connectPage(Request $request): Response
    {
        $pageId = $request->request->get('pageId');
        $session = $request->getSession();

        $pagesSession = $session->get('fb_pages_list', []);
        $setting = $this->facebookService->getEffectiveSetting();
        $appId = $session->get('fb_connect_app_id') ?: ($setting ? $setting->getAppId() : null);
        $appSecret = $session->get('fb_connect_app_secret') ?: ($setting ? $this->facebookService->decryptToken($setting->getEncryptedAppSecret()) : null);
        $label = $session->get('fb_connect_label');

        if (!$pageId || !isset($pagesSession[$pageId]) || !$appId || !$appSecret) {
            $this->addFlash('error', 'Session expired or invalid page selection. Please try again.');
            return $this->redirectToRoute('facebook_connect_show');
        }

        $pageData = $pagesSession[$pageId];

        try {
            // Save the connection (which encrypts the tokens)
            $connection = $this->facebookService->saveConnection(
                $pageData['id'],
                $pageData['access_token'],
                $appId,
                $appSecret,
                $pageData['name'],
                $label ?: null
            );

            // Sync persistent menu if it exists on Facebook
            try {
                $this->facebookService->syncPersistentMenuFromFacebook($connection);
            } catch (\Exception $e) {
                // Fail silently so it doesn't block page connection setup
            }

            // Sync welcome screen if it exists on Facebook
            try {
                $this->facebookService->syncWelcomeScreenFromFacebook($connection);
            } catch (\Exception $e) {
                // Fail silently so it doesn't block page connection setup
            }

            // Clear connection session data
            $session->remove('fb_pages_list');
            $session->remove('fb_connect_app_id');
            $session->remove('fb_connect_app_secret');
            $session->remove('fb_connect_label');
            $session->remove('fb_connect_state');

            // Subscribe Page to App Webhooks
            $subResult = $this->facebookService->subscribePage($pageData['id'], $pageData['access_token']);
            if (isset($subResult['success']) && $subResult['success']) {
                $this->addFlash('success', sprintf('Successfully connected Facebook Page and subscribed webhooks: %s!', $pageData['name']));
            } else {
                $this->addFlash('warning', sprintf('Connected Facebook Page %s, but failed to subscribe webhooks: %s', $pageData['name'], $subResult['error'] ?? 'Unknown error'));
            }
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to save connection: ' . $e->getMessage());
        }

        return $this->redirectToRoute('facebook_connect_show');
    }

    #[Route('/facebook/connect/{id}/edit', name: 'facebook_connect_edit', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function edit(int $id): Response
    {
        $connection = $this->facebookService->getConnectionById($id);
        if (!$connection) {
            $this->addFlash('error', 'Connection not found.');
            return $this->redirectToRoute('facebook_connect_show');
        }

        $connections = $this->facebookService->getAllConnections();
        $setting = $this->facebookService->getEffectiveSetting();

        return $this->render('facebook/connect.html.twig', [
            'connections' => $connections,
            'editConnection' => $connection,
            'setting' => $setting,
        ]);
    }

    #[Route('/facebook/connect/{id}/update', name: 'facebook_connect_update', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function update(int $id, Request $request): Response
    {
        $label = trim($request->request->get('label', ''));

        try {
            $connection = $this->facebookService->getConnectionById($id);
            if (!$connection) {
                $this->addFlash('error', 'Connection not found.');
                return $this->redirectToRoute('facebook_connect_show');
            }

            if ($label !== '') {
                $connection->setLabel($label);
                $this->facebookService->saveConnection(
                    $connection->getPageId(),
                    $this->facebookService->decryptToken($connection->getEncryptedPageAccessToken()),
                    $connection->getAppId(),
                    $this->facebookService->decryptToken($connection->getEncryptedAppSecret()),
                    $connection->getPageName(),
                    $label,
                    $id
                );
                $this->addFlash('success', 'Connection label updated successfully!');
            }
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to update connection: ' . $e->getMessage());
        }

        return $this->redirectToRoute('facebook_connect_show');
    }

    #[Route('/facebook/connect/{id}/delete', name: 'facebook_connect_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id): JsonResponse
    {
        $success = $this->facebookService->deleteConnection($id);
        return new JsonResponse(['success' => $success]);
    }

    #[Route('/facebook/connect/{id}/subscribe', name: 'facebook_connect_subscribe', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function subscribe(int $id): Response
    {
        try {
            $connection = $this->facebookService->getConnectionById($id);
            if (!$connection) {
                $this->addFlash('error', 'Connection not found.');
                return $this->redirectToRoute('facebook_connect_show');
            }

            $pageId = $connection->getPageId();
            $pageAccessToken = $this->facebookService->decryptToken($connection->getEncryptedPageAccessToken());

            $subResult = $this->facebookService->subscribePage($pageId, $pageAccessToken);

            if (isset($subResult['success']) && $subResult['success']) {
                $this->addFlash('success', sprintf('Successfully subscribed webhooks for Facebook Page: %s!', $connection->getPageName()));
            } else {
                $this->addFlash('error', sprintf('Failed to subscribe webhooks for Facebook Page: %s. Error: %s', $connection->getPageName(), $subResult['error'] ?? 'Unknown error'));
            }
        } catch (\Exception $e) {
            $this->addFlash('error', 'An error occurred during webhook subscription: ' . $e->getMessage());
        }

        return $this->redirectToRoute('facebook_connect_show');
    }

    #[Route('/facebook/data-deletion', name: 'facebook_data_deletion', methods: ['POST'])]
    public function dataDeletion(Request $request): JsonResponse
    {
        $signedRequest = $request->request->get('signed_request');
        if (!$signedRequest) {
            return new JsonResponse(['error' => 'Missing signed_request parameter.'], 400);
        }

        $data = $this->facebookService->parseSignedRequest($signedRequest);
        if (!$data) {
            return new JsonResponse(['error' => 'Invalid signed_request.'], 400);
        }

        $userId = $data['user_id'] ?? 'unknown';
        // Generate a unique tracking confirmation code
        $confirmationCode = 'del_' . substr(hash('sha256', $userId . time() . uniqid('', true)), 0, 16);

        // Generate absolute status check URL, enforcing HTTPS
        $statusUrl = $this->generateUrl('facebook_deletion_status', ['code' => $confirmationCode], UrlGeneratorInterface::ABSOLUTE_URL);
        $statusUrl = str_replace('http://', 'https://', $statusUrl);

        return new JsonResponse([
            'url' => $statusUrl,
            'confirmation_code' => $confirmationCode,
        ]);
    }

    #[Route('/facebook/deletion-status', name: 'facebook_deletion_status', methods: ['GET'])]
    public function deletionStatus(Request $request): Response
    {
        $code = $request->query->get('code', '');

        return $this->render('facebook/deletion_status.html.twig', [
            'code' => $code,
        ]);
    }
}

