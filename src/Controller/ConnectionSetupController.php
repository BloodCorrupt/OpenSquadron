<?php

namespace App\Controller;

use App\Service\WhatsAppConnectionService;
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
        #[Autowire('%env(WHATSAPP_VERIFY_TOKEN)%')]
        private string $envVerifyToken
    ) {
    }

    #[Route('/admin/whatsapp/connect', name: 'whatsapp_connect_show', methods: ['GET'])]
    public function show(): Response
    {
        $connections = $this->whatsappService->getAllConnections();

        return $this->render('whatsapp/connect.html.twig', [
            'connections' => $connections,
            'fallbackVerifyToken' => $this->envVerifyToken,
        ]);
    }

    #[Route('/admin/whatsapp/connect', name: 'whatsapp_connect', methods: ['POST'])]
    public function connect(Request $request): Response
    {
        $businessAccountId = trim($request->request->get('businessAccountId', ''));
        $accessToken = trim($request->request->get('accessToken', ''));
        $phoneNumberId = trim($request->request->get('phoneNumberId', ''));
        $label = trim($request->request->get('label', ''));
        $phoneNumber = trim($request->request->get('phoneNumber', ''));

        if (empty($businessAccountId) || empty($accessToken)) {
            $this->addFlash('error', 'Business Account ID and Access Token are required.');
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
                $phoneNumberId ?: null,
                $label ?: null,
                $phoneNumber ?: null
            );
            $this->addFlash('success', 'WhatsApp Connection saved and validated successfully!');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to save connection: ' . $e->getMessage());
        }

        return $this->redirectToRoute('whatsapp_connect_show');
    }

    #[Route('/admin/whatsapp/connect/{id}/edit', name: 'whatsapp_connect_edit', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function edit(int $id): Response
    {
        $connection = $this->whatsappService->getConnectionById($id);
        if (!$connection) {
            $this->addFlash('error', 'Connection not found.');
            return $this->redirectToRoute('whatsapp_connect_show');
        }

        $connections = $this->whatsappService->getAllConnections();

        return $this->render('whatsapp/connect.html.twig', [
            'connections' => $connections,
            'editConnection' => $connection,
            'fallbackVerifyToken' => $this->envVerifyToken,
        ]);
    }

    #[Route('/admin/whatsapp/connect/{id}/update', name: 'whatsapp_connect_update', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function update(int $id, Request $request): Response
    {
        $businessAccountId = trim($request->request->get('businessAccountId', ''));
        $accessToken = trim($request->request->get('accessToken', ''));
        $phoneNumberId = trim($request->request->get('phoneNumberId', ''));
        $label = trim($request->request->get('label', ''));
        $phoneNumber = trim($request->request->get('phoneNumber', ''));

        if (empty($businessAccountId)) {
            $this->addFlash('error', 'Business Account ID is required.');
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
                $phoneNumberId ?: null,
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

    #[Route('/admin/whatsapp/connect/{id}/delete', name: 'whatsapp_connect_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id): JsonResponse
    {
        $success = $this->whatsappService->deleteConnection($id);
        return new JsonResponse(['success' => $success]);
    }
}
