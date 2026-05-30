<?php
namespace App\Controller;

use App\Entity\FacebookConnection;
use App\Service\FacebookService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class DebugController extends AbstractController
{
    #[Route('/debug-sync', name: 'debug_sync', methods: ['GET'])]
    public function debugSync(EntityManagerInterface $em, FacebookService $facebookService): JsonResponse
    {
        $connection = $em->getRepository(FacebookConnection::class)->find(1);
        if (!$connection) {
            return new JsonResponse(['error' => 'No connection found.']);
        }
        try {
            $welcomeSettings = $facebookService->syncWelcomeScreenFromFacebook($connection);
            return new JsonResponse(['success' => true, 'data' => $welcomeSettings]);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()]);
        }
    }

    #[Route('/debug-save', name: 'debug_save', methods: ['GET'])]
    public function debugSave(EntityManagerInterface $em, FacebookService $facebookService): JsonResponse
    {
        $connection = $em->getRepository(FacebookConnection::class)->find(1);
        if (!$connection) {
            return new JsonResponse(['error' => 'No connection found.']);
        }
        try {
            $settings = $connection->getBotSettings()['welcome-screen'] ?? [];
            $settings['showGreeting'] = true;
            $settings['greetingText'] = 'Test greeting';
            $settings['getStartedStatus'] = 'enabled';
            $settings['getStartedPayload'] = 'TEST_PAYLOAD';
            
            $result = $facebookService->setWelcomeScreenSettings($connection, $settings);
            return new JsonResponse(['success' => true, 'data' => $result]);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()]);
        }
    }

    #[Route('/debug-raw', name: 'debug_raw', methods: ['GET'])]
    public function debugRaw(EntityManagerInterface $em, FacebookService $facebookService): JsonResponse
    {
        $connection = $em->getRepository(FacebookConnection::class)->find(1);
        if (!$connection) {
            return new JsonResponse(['error' => 'No connection found.']);
        }
        try {
            $data = $facebookService->getWelcomeScreenSettings($connection);
            return new JsonResponse(['success' => true, 'data' => $data]);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()]);
        }
    }
}
