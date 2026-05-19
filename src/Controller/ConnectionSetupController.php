<?php

namespace App\Controller;

use App\Service\WhatsAppConnectionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
        $connection = $this->whatsappService->getConnection();

        return $this->render('whatsapp/connect.html.twig', [
            'connection' => $connection,
            'fallbackVerifyToken' => $this->envVerifyToken,
        ]);
    }

    #[Route('/admin/whatsapp/connect', name: 'whatsapp_connect', methods: ['POST'])]
    public function connect(Request $request): Response
    {
        $businessAccountId = $request->request->get('businessAccountId');
        $accessToken = $request->request->get('accessToken');
        $phoneNumberId = $request->request->get('phoneNumberId');

        $connection = $this->whatsappService->getConnection();

        // If connection exists, access token might be empty (which means we keep the old one)
        if ($connection && empty($accessToken)) {
            // We just update the business account ID if it changed
            if ($businessAccountId !== $connection->getBusinessAccountId()) {
                // Actually, if they don't provide an access token, we can't really validate it easily unless we decrypt.
                // Let's decrypt the existing one to validate.
                try {
                    $accessToken = $this->whatsappService->decryptToken($connection->getEncryptedAccessToken());
                } catch (\Exception $e) {
                    $this->addFlash('error', 'Encryption key error or corrupted token. Please re-enter your access token.');
                    return $this->redirectToRoute('whatsapp_connect_show');
                }
            } else {
                // Nothing really changed that requires validation. We can just save it.
                // But let's just make them enter it if they want to change business ID.
                $this->addFlash('success', 'Settings updated.');
                return $this->redirectToRoute('whatsapp_connect_show');
            }
        }

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
            $this->whatsappService->saveConnection($businessAccountId, $accessToken, $phoneNumberId);
            $this->addFlash('success', 'WhatsApp Connection saved and validated successfully!');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to save connection: ' . $e->getMessage());
        }

        return $this->redirectToRoute('whatsapp_connect_show');
    }
}
