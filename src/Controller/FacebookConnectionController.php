<?php

namespace App\Controller;

use App\Service\FacebookService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

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
        $pageId = trim($request->request->get('pageId', ''));
        $pageAccessToken = trim($request->request->get('pageAccessToken', ''));
        $appId = trim($request->request->get('appId', ''));
        $appSecret = trim($request->request->get('appSecret', ''));
        $pageName = trim($request->request->get('pageName', ''));
        $label = trim($request->request->get('label', ''));

        if (empty($pageId) || empty($pageAccessToken) || empty($appId) || empty($appSecret)) {
            $this->addFlash('error', 'Page ID, Page Access Token, App ID, and App Secret are required.');
            return $this->redirectToRoute('facebook_connect_show');
        }

        // Validate with Graph API
        $validationResult = $this->facebookService->validateWithGraphApi($pageId, $pageAccessToken);

        if (!$validationResult['success']) {
            $this->addFlash('error', 'Failed to validate with Graph API: ' . $validationResult['error']);
            return $this->redirectToRoute('facebook_connect_show');
        }

        try {
            $this->facebookService->saveConnection(
                $pageId,
                $pageAccessToken,
                $appId,
                $appSecret,
                $pageName ?: null,
                $label ?: null
            );
            $this->addFlash('success', 'Facebook Page Connection saved and validated successfully!');
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
        $pageId = trim($request->request->get('pageId', ''));
        $pageAccessToken = trim($request->request->get('pageAccessToken', ''));
        $appId = trim($request->request->get('appId', ''));
        $appSecret = trim($request->request->get('appSecret', ''));
        $pageName = trim($request->request->get('pageName', ''));
        $label = trim($request->request->get('label', ''));

        if (empty($pageId) || empty($appId)) {
            $this->addFlash('error', 'Page ID and App ID are required.');
            return $this->redirectToRoute('facebook_connect_edit', ['id' => $id]);
        }

        // If a new page access token is provided, validate it
        if (!empty($pageAccessToken)) {
            $validationResult = $this->facebookService->validateWithGraphApi($pageId, $pageAccessToken);
            if (!$validationResult['success']) {
                $this->addFlash('error', 'Failed to validate with Graph API: ' . $validationResult['error']);
                return $this->redirectToRoute('facebook_connect_edit', ['id' => $id]);
            }
        }

        try {
            $result = $this->facebookService->updateConnection(
                $id,
                $pageId,
                $pageAccessToken ?: null,
                $appId,
                $appSecret ?: null,
                $pageName ?: null,
                $label ?: null
            );

            if (!$result) {
                $this->addFlash('error', 'Connection not found.');
            } else {
                $this->addFlash('success', 'Connection updated successfully!');
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
