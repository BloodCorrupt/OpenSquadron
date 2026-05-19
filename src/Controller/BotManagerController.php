<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class BotManagerController extends AbstractController
{
    #[Route('/admin/bot-manager', name: 'app_bot_manager')]
    public function index(): Response
    {
        return $this->render('bot_manager/index.html.twig', [
            'controller_name' => 'BotManagerController',
        ]);
    }

    #[Route('/admin/bot-manager/templates', name: 'app_bot_templates', methods: ['GET'])]
    public function templates(\Doctrine\ORM\EntityManagerInterface $em): Response
    {
        $templates = $em->getRepository(\App\Entity\MessageTemplate::class)->findAll();
        
        return $this->render('bot_manager/templates.html.twig', [
            'templates' => $templates,
        ]);
    }

    #[Route('/admin/bot-manager/templates/sync', name: 'app_bot_templates_sync', methods: ['POST'])]
    public function syncTemplates(\App\Service\WhatsAppConnectionService $whatsappService): \Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $result = $whatsappService->syncTemplates();
            return new \Symfony\Component\HttpFoundation\JsonResponse([
                'success' => true,
                'message' => "Successfully synced {$result['count']} approved templates."
            ]);
        } catch (\Exception $e) {
            return new \Symfony\Component\HttpFoundation\JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    #[Route('/admin/bot-manager/templates/create', name: 'app_bot_templates_create', methods: ['POST'])]
    public function createTemplate(
        \Symfony\Component\HttpFoundation\Request $request,
        \App\Service\WhatsAppConnectionService $whatsappService
    ): \Symfony\Component\HttpFoundation\JsonResponse {
        $name     = trim($request->request->get('name', ''));
        $language = $request->request->get('language', 'en_US');
        $category = $request->request->get('category', 'UTILITY');
        $body     = trim($request->request->get('body', ''));
        $header   = trim($request->request->get('header', '')) ?: null;
        $footer   = trim($request->request->get('footer', '')) ?: null;

        if (!$name || !$body) {
            return new \Symfony\Component\HttpFoundation\JsonResponse(['success' => false, 'error' => 'Name and Body are required.'], 400);
        }

        try {
            // 1. Submit template to Meta
            $result = $whatsappService->createTemplate($name, $language, $category, $body, $header, $footer);

            // 2. Auto-sync back so the new template appears in our DB immediately
            try {
                $whatsappService->syncTemplates();
            } catch (\Exception $syncEx) {
                // Sync failure is non-critical — template was still created on Meta
            }

            return new \Symfony\Component\HttpFoundation\JsonResponse([
                'success' => true,
                'message' => 'Template submitted to Meta! It will appear as APPROVED once Meta reviews it.',
                'id' => $result['id'] ?? null,
            ]);
        } catch (\Exception $e) {
            return new \Symfony\Component\HttpFoundation\JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 422);
        }
    }

    #[Route('/admin/bot-manager/flows', name: 'app_bot_flows', methods: ['GET'])]
    public function flows(\Doctrine\ORM\EntityManagerInterface $em): Response
    {
        $flows = $em->getRepository(\App\Entity\BotFlow::class)->findAll();
        
        return $this->render('bot_manager/flows.html.twig', [
            'flows' => $flows,
        ]);
    }

    #[Route('/admin/bot-manager/flows/save', name: 'app_bot_flows_save', methods: ['POST'])]
    public function saveFlow(\Symfony\Component\HttpFoundation\Request $request, \Doctrine\ORM\EntityManagerInterface $em): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        
        if (!$payload || !isset($payload['keyword']) || !isset($payload['actions'])) {
            return new \Symfony\Component\HttpFoundation\JsonResponse(['success' => false, 'error' => 'Invalid payload'], 400);
        }

        $flow = $em->getRepository(\App\Entity\BotFlow::class)->findOneBy(['triggerKeyword' => strtolower($payload['keyword'])]);
        
        if (!$flow) {
            $flow = new \App\Entity\BotFlow();
            $flow->setTriggerKeyword(strtolower($payload['keyword']));
        }
        
        $flow->setFlowData($payload['actions']);
        $flow->setActive($payload['isActive'] ?? true);
        
        $em->persist($flow);
        $em->flush();

        return new \Symfony\Component\HttpFoundation\JsonResponse(['success' => true]);
    }
}
