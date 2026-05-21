<?php

namespace App\Controller;

use App\Entity\WhatsappBotFlow;
use App\Entity\MessageTemplate;
use App\Entity\AiSetting;
use App\Entity\AiContext;
use App\Service\WhatsAppConnectionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class WhatsappBotManagerController extends AbstractController
{
    #[Route('/whatsapp-bot-manager', name: 'app_whatsapp_bot_manager')]
    public function index(Request $request, EntityManagerInterface $em): Response
    {
        $connections = $em->getRepository(\App\Entity\WhatsAppConnection::class)->findBy([], ['id' => 'DESC']);
        
        $selectedConnectionId = $request->query->get('connectionId');
        $selectedConnection = null;
        if ($selectedConnectionId) {
            $selectedConnection = $em->getRepository(\App\Entity\WhatsAppConnection::class)->find($selectedConnectionId);
        }
        if (!$selectedConnection && !empty($connections)) {
            $selectedConnection = $connections[0];
        }

        $flows = [];
        $templates = [];
        if ($selectedConnection) {
            $flows = $em->getRepository(WhatsappBotFlow::class)->findBy(['whatsAppConnection' => $selectedConnection], ['id' => 'DESC']);
            $templates = $em->getRepository(MessageTemplate::class)->findBy(['whatsAppConnection' => $selectedConnection], ['id' => 'DESC']);
        }

        $aiSetting = $em->getRepository(AiSetting::class)->findOneBy([]) ?? new AiSetting();
        $contexts = $em->getRepository(AiContext::class)->findBy([], ['id' => 'DESC']);

        return $this->render('whatsapp_bot_manager/index.html.twig', [
            'flows' => $flows,
            'templates' => $templates,
            'aiSetting' => $aiSetting,
            'connection' => $selectedConnection,
            'connections' => $connections,
            'contexts' => $contexts,
        ]);
    }

    #[Route('/whatsapp-bot-manager/flows/{id}/clone', name: 'app_whatsapp_bot_flows_clone', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function cloneFlow(int $id, EntityManagerInterface $em): JsonResponse
    {
        $flow = $em->getRepository(WhatsappBotFlow::class)->find($id);
        if (!$flow) {
            return new JsonResponse(['success' => false, 'error' => 'Flow not found.'], 404);
        }

        $clone = new WhatsappBotFlow();
        $clone->setName($flow->getName() . ' (Copy)');
        $clone->setTriggerKeyword($flow->getTriggerKeyword());
        $clone->setMatchMode($flow->getMatchMode());
        $clone->setActive($flow->isActive());
        $clone->setFlowData($flow->getFlowData());
        $clone->setWhatsAppConnection($flow->getWhatsAppConnection());

        $em->persist($clone);
        $em->flush();

        return new JsonResponse(['success' => true]);
    }

    #[Route('/whatsapp-bot-manager/flows/{id}/export', name: 'app_whatsapp_bot_flows_export', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function exportFlow(int $id, EntityManagerInterface $em): Response
    {
        $flow = $em->getRepository(WhatsappBotFlow::class)->find($id);
        if (!$flow) {
            throw $this->createNotFoundException('Flow not found.');
        }

        $data = [
            'name' => $flow->getName(),
            'keywords' => $flow->getTriggerKeyword(),
            'matchMode' => $flow->getMatchMode(),
            'isActive' => $flow->isActive(),
            'flowData' => $flow->getFlowData(),
        ];

        $response = new Response(json_encode($data, JSON_PRETTY_PRINT));
        $response->headers->set('Content-Type', 'application/json');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $flow->getName()) . '_flow.json"');

        return $response;
    }

    // ───────────────────────── Templates ─────────────────────────

    #[Route('/whatsapp-bot-manager/templates', name: 'app_whatsapp_bot_templates', methods: ['GET'])]
    public function templates(): Response
    {
        return $this->redirectToRoute('app_whatsapp_bot_manager', ['tab' => 'templates']);
    }

    #[Route('/whatsapp-bot-manager/templates/sync', name: 'app_whatsapp_bot_templates_sync', methods: ['POST'])]
    public function syncTemplates(Request $request, WhatsAppConnectionService $whatsappService, EntityManagerInterface $em): JsonResponse
    {
        $connectionId = $request->request->get('connectionId');
        $connection = null;
        if ($connectionId) {
            $connection = $em->getRepository(\App\Entity\WhatsAppConnection::class)->find($connectionId);
        }

        try {
            $result = $whatsappService->syncTemplates($connection);
            return new JsonResponse([
                'success' => true,
                'message' => "Successfully synced {$result['count']} approved templates.",
            ]);
        } catch (\Exception $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[Route('/whatsapp-bot-manager/templates/create', name: 'app_whatsapp_bot_templates_create', methods: ['POST'])]
    public function createTemplate(Request $request, WhatsAppConnectionService $whatsappService, EntityManagerInterface $em): JsonResponse
    {
        $connectionId = $request->request->get('connectionId');
        $connection = null;
        if ($connectionId) {
            $connection = $em->getRepository(\App\Entity\WhatsAppConnection::class)->find($connectionId);
        }

        $name     = trim($request->request->get('name', ''));
        $language = $request->request->get('language', 'en_US');
        $category = $request->request->get('category', 'UTILITY');
        $body     = trim($request->request->get('body', ''));
        $header   = trim($request->request->get('header', '')) ?: null;
        $footer   = trim($request->request->get('footer', '')) ?: null;

        if (!$name || !$body) {
            return new JsonResponse(['success' => false, 'error' => 'Name and Body are required.'], 400);
        }

        try {
            $result = $whatsappService->createTemplate($name, $language, $category, $body, $header, $footer, $connection);
            try {
                $whatsappService->syncTemplates($connection);
            } catch (\Exception $syncEx) {
                // Sync failure is non-critical — Meta still has the template.
            }

            return new JsonResponse([
                'success' => true,
                'message' => 'Template submitted to Meta! It will appear as APPROVED once Meta reviews it.',
                'id' => $result['id'] ?? null,
            ]);
        } catch (\Exception $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 422);
        }
    }

    // ───────────────────────── Flows ─────────────────────────

    #[Route('/whatsapp-bot-manager/flows', name: 'app_whatsapp_bot_flows', methods: ['GET'])]
    public function flows(Request $request, EntityManagerInterface $em): Response
    {
        $connections = $em->getRepository(\App\Entity\WhatsAppConnection::class)->findBy([], ['id' => 'DESC']);
        
        $selectedConnectionId = $request->query->get('connectionId');
        $selectedConnection = null;
        if ($selectedConnectionId) {
            $selectedConnection = $em->getRepository(\App\Entity\WhatsAppConnection::class)->find($selectedConnectionId);
        }
        if (!$selectedConnection && !empty($connections)) {
            $selectedConnection = $connections[0];
        }

        $flows = [];
        $templates = [];
        if ($selectedConnection) {
            $flows = $em->getRepository(WhatsappBotFlow::class)->findBy(['whatsAppConnection' => $selectedConnection], ['id' => 'DESC']);
            $templates = $em->getRepository(MessageTemplate::class)->findBy([
                'status' => 'APPROVED',
                'whatsAppConnection' => $selectedConnection
            ], ['id' => 'DESC']);
        }

        $payload = array_map(static fn (WhatsappBotFlow $f) => self::flowToArray($f), $flows);

        return $this->render('whatsapp_bot_manager/flows.html.twig', [
            'flows'       => $flows,
            'flowsJson'   => $payload,
            'templates'   => $templates,
            'connection'  => $selectedConnection,
        ]);
    }

    #[Route('/whatsapp-bot-manager/flows/save', name: 'app_whatsapp_bot_flows_save', methods: ['POST'])]
    public function saveFlow(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid payload'], 400);
        }

        $id        = isset($payload['id']) ? (int)$payload['id'] : null;
        $name      = trim((string)($payload['name'] ?? ''));
        $keywords  = $this->normaliseKeywords($payload['keywords'] ?? '');
        $matchMode = (string)($payload['matchMode'] ?? WhatsappBotFlow::MATCH_EXACT);
        $isActive  = (bool)($payload['isActive'] ?? true);
        $graph     = $payload['graph'] ?? null;

        if ($name === '') {
            return new JsonResponse(['success' => false, 'error' => 'Flow name is required.'], 400);
        }
        if ($keywords === '') {
            return new JsonResponse(['success' => false, 'error' => 'At least one trigger keyword is required.'], 400);
        }
        if (!\is_array($graph)) {
            return new JsonResponse(['success' => false, 'error' => 'Flow graph payload missing.'], 400);
        }

        $flow = $id ? $em->getRepository(WhatsappBotFlow::class)->find($id) : null;
        if (!$flow) {
            $flow = new WhatsappBotFlow();
        }

        // Get selected connection
        $connectionId = isset($payload['connectionId']) ? (int)$payload['connectionId'] : (int)$request->query->get('connectionId');
        $connection = null;
        if ($connectionId) {
            $connection = $em->getRepository(\App\Entity\WhatsAppConnection::class)->find($connectionId);
        }
        if (!$connection) {
            // Default to the first connection
            $connections = $em->getRepository(\App\Entity\WhatsAppConnection::class)->findBy([], ['id' => 'DESC']);
            $connection = $connections[0] ?? null;
        }

        $flow->setWhatsAppConnection($connection);
        $flow->setName($name);
        $flow->setTriggerKeyword($keywords);
        try {
            $flow->setMatchMode($matchMode);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 400);
        }
        $flow->setActive($isActive);
        $flow->setFlowData([
            'format'   => 'graph',
            'nodes'    => $graph['nodes'] ?? [],
            'edges'    => $graph['edges'] ?? [],
            'viewport' => $graph['viewport'] ?? null,
        ]);

        $em->persist($flow);
        $em->flush();

        return new JsonResponse(['success' => true, 'flow' => self::flowToArray($flow)]);
    }

    #[Route('/whatsapp-bot-manager/flows/{id}/delete', name: 'app_whatsapp_bot_flows_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function deleteFlow(int $id, EntityManagerInterface $em): JsonResponse
    {
        $flow = $em->getRepository(WhatsappBotFlow::class)->find($id);
        if (!$flow) {
            return new JsonResponse(['success' => false, 'error' => 'Flow not found.'], 404);
        }
        $em->remove($flow);
        $em->flush();

        return new JsonResponse(['success' => true]);
    }

    #[Route('/whatsapp-bot-manager/flows/{id}/toggle', name: 'app_whatsapp_bot_flows_toggle', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function toggleFlow(int $id, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $flow = $em->getRepository(WhatsappBotFlow::class)->find($id);
        if (!$flow) {
            return new JsonResponse(['success' => false, 'error' => 'Flow not found.'], 404);
        }

        $payload = json_decode($request->getContent(), true);
        $isActive = isset($payload['isActive']) ? (bool)$payload['isActive'] : !$flow->isActive();

        $flow->setActive($isActive);
        $em->flush();

        return new JsonResponse(['success' => true, 'isActive' => $flow->isActive()]);
    }

    #[Route('/whatsapp-bot-manager/ai-settings/agent', name: 'app_whatsapp_bot_ai_agent_save', methods: ['POST'])]
    public function saveAiAgentSettings(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $connectionId = $request->request->get('connectionId');
        $connection = null;
        if ($connectionId) {
            $connection = $em->getRepository(\App\Entity\WhatsAppConnection::class)->find($connectionId);
        }
        if (!$connection) {
            $connection = $em->getRepository(\App\Entity\WhatsAppConnection::class)->findOneBy([]);
        }

        if (!$connection) {
            return new JsonResponse(['success' => false, 'error' => 'No WhatsApp Connection found.']);
        }

        $aiActive = (bool)$request->request->get('aiActive', false);
        $activeContextId = $request->request->get('activeContextId');

        $connection->setAiActive($aiActive);

        if ($request->request->has('agentName')) {
            $connection->setAgentName(trim($request->request->get('agentName', '')) ?: null);
        }
        if ($request->request->has('agentRole')) {
            $connection->setAgentRole(trim($request->request->get('agentRole', '')) ?: null);
        }
        if ($request->request->has('contextData')) {
            $connection->setContextData(trim($request->request->get('contextData', '')) ?: null);
        }

        if ($activeContextId !== null && $activeContextId !== '') {
            $context = $em->getRepository(\App\Entity\AiContext::class)->find($activeContextId);
            $connection->setActiveContext($context);
        } else {
            $connection->setActiveContext(null);
        }

        $em->persist($connection);
        $em->flush();

        return new JsonResponse([
            'success' => true,
            'message' => 'WhatsApp AI Agent Settings saved successfully.',
        ]);
    }


    // ───────────────────────── helpers ─────────────────────────

    /**
     * @param mixed $raw  Either a string or an array of keywords from the client.
     */
    private function normaliseKeywords(mixed $raw): string
    {
        if (\is_array($raw)) {
            $parts = $raw;
        } else {
            $parts = explode(',', (string)$raw);
        }
        $parts = array_map(
            static fn ($k): string => strtolower(trim((string)$k)),
            $parts
        );
        $parts = array_values(array_unique(array_filter($parts, static fn (string $k): bool => $k !== '')));

        return implode(',', $parts);
    }

    /**
     * @return array<string, mixed>
     */
    private static function flowToArray(WhatsappBotFlow $flow): array
    {
        $data = $flow->getFlowData();
        $isGraph = isset($data['format']) && $data['format'] === 'graph';

        return [
            'id'        => $flow->getId(),
            'name'      => $flow->getName() ?? ('Flow #' . $flow->getId()),
            'keywords'  => $flow->getKeywordList(),
            'matchMode' => $flow->getMatchMode(),
            'isActive'  => (bool)$flow->isActive(),
            'graph'     => $isGraph
                ? [
                    'nodes'    => $data['nodes'] ?? [],
                    'edges'    => $data['edges'] ?? [],
                    'viewport' => $data['viewport'] ?? null,
                ]
                : null,
            'legacyActions' => $isGraph ? null : $data,
        ];
    }
}
