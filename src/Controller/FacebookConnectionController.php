<?php

namespace App\Controller;

use App\Service\FacebookService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class FacebookConnectionController extends AbstractController
{
    public function __construct(
        private FacebookService $facebookService
    ) {
    }

    #[Route('/admin/facebook/connect', name: 'facebook_connect_show', methods: ['GET'])]
    public function show(): Response
    {
        $connections = $this->facebookService->getAllConnections();

        return $this->render('facebook/connect.html.twig', [
            'connections' => $connections,
        ]);
    }

    #[Route('/admin/facebook/connect', name: 'facebook_connect', methods: ['POST'])]
    public function connect(Request $request): Response
    {
        $appId = trim($request->request->get('appId', ''));
        $appSecret = trim($request->request->get('appSecret', ''));
        $label = trim($request->request->get('label', ''));

        if (empty($appId) || empty($appSecret)) {
            $this->addFlash('error', 'App ID and App Secret are required to initiate connection.');
            return $this->redirectToRoute('facebook_connect_show');
        }

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
        $appId = $session->get('fb_connect_app_id');
        $appSecret = $session->get('fb_connect_app_secret');

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

    #[Route('/admin/facebook/connect-page', name: 'facebook_connect_page_submit', methods: ['POST'])]
    public function connectPage(Request $request): Response
    {
        $pageId = $request->request->get('pageId');
        $session = $request->getSession();

        $pagesSession = $session->get('fb_pages_list', []);
        $appId = $session->get('fb_connect_app_id');
        $appSecret = $session->get('fb_connect_app_secret');
        $label = $session->get('fb_connect_label');

        if (!$pageId || !isset($pagesSession[$pageId]) || !$appId || !$appSecret) {
            $this->addFlash('error', 'Session expired or invalid page selection. Please try again.');
            return $this->redirectToRoute('facebook_connect_show');
        }

        $pageData = $pagesSession[$pageId];

        try {
            // Save the connection (which encrypts the tokens)
            $this->facebookService->saveConnection(
                $pageData['id'],
                $pageData['access_token'],
                $appId,
                $appSecret,
                $pageData['name'],
                $label ?: null
            );

            // Clear connection session data
            $session->remove('fb_pages_list');
            $session->remove('fb_connect_app_id');
            $session->remove('fb_connect_app_secret');
            $session->remove('fb_connect_label');
            $session->remove('fb_connect_state');

            $this->addFlash('success', sprintf('Successfully connected Facebook Page: %s!', $pageData['name']));
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to save connection: ' . $e->getMessage());
        }

        return $this->redirectToRoute('facebook_connect_show');
    }

    #[Route('/admin/facebook/connect/{id}/edit', name: 'facebook_connect_edit', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function edit(int $id): Response
    {
        $connection = $this->facebookService->getConnectionById($id);
        if (!$connection) {
            $this->addFlash('error', 'Connection not found.');
            return $this->redirectToRoute('facebook_connect_show');
        }

        $connections = $this->facebookService->getAllConnections();

        return $this->render('facebook/connect.html.twig', [
            'connections' => $connections,
            'editConnection' => $connection,
        ]);
    }

    #[Route('/admin/facebook/connect/{id}/update', name: 'facebook_connect_update', methods: ['POST'], requirements: ['id' => '\d+'])]
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

    #[Route('/admin/facebook/connect/{id}/delete', name: 'facebook_connect_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id): JsonResponse
    {
        $success = $this->facebookService->deleteConnection($id);
        return new JsonResponse(['success' => $success]);
    }
}
