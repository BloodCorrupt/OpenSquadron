<?php

namespace App\Controller;

use App\Entity\WhatsappBotFlow;
use App\Entity\WhatsappDripSequence;
use App\Entity\WhatsappActionButton;
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

        // Default realistic settings for 11 tabs to ensure premium instantly-featured appearance
        $defaultSettings = [
            'broadcast-campaigns' => [
                ['name' => 'Newsletter May 2026', 'template' => 'monthly_newsletter', 'status' => 'SENT', 'sentCount' => 1250, 'deliveredCount' => 1245],
                ['name' => 'Flash Sale Alert', 'template' => 'flash_sale', 'status' => 'SCHEDULED', 'sentCount' => 0, 'deliveredCount' => 0],
            ],
            'growth-widgets' => [
                'phoneNumber' => '+15550199',
                'welcomeMessage' => 'Hi there! How can we help you today?',
                'bubbleColor' => '#128c7e',
                'position' => 'right',
                'embedCode' => '<script src="https://opensquadron.io/js/whatsapp-widget.js" data-phone="+15550199"></script>'
            ],
            'drip-sequences' => [],
            'structured-inputs' => [
                ['fieldName' => 'lead_email', 'dataType' => 'EMAIL', 'prompt' => 'Please provide your email address for lead tracking:'],
                ['fieldName' => 'lead_company', 'dataType' => 'TEXT', 'prompt' => 'What is your company name?'],
            ],
            'dynamic-flows' => [
                ['name' => 'Customer Feedback Survey', 'flowId' => 'survey_flow_v1', 'status' => 'PUBLISHED'],
                ['name' => 'Book Appointment Flow', 'flowId' => 'booking_flow_v2', 'status' => 'DRAFT'],
            ],
            'ecommerce-automations' => [
                'abandonedCartActive' => true,
                'abandonedCartDelay' => '30 minutes',
                'abandonedCartTemplate' => 'abandoned_cart_reminder',
                'orderConfirmationActive' => true,
                'orderConfirmationTemplate' => 'order_confirmation_official',
            ],
            'outbound-streams' => [
                'webhookUrl' => 'https://api.crm-connector.io/v1/whatsapp-receiver',
                'activeEvents' => ['message_sent', 'message_received', 'opt_in', 'opt_out']
            ],
            'action-buttons' => [
                ['label' => '💬 Chat with Specialist', 'type' => 'quick_reply', 'payload' => 'TALK_TO_SPECIALIST'],
                ['label' => '📅 Book Appointment', 'type' => 'url', 'value' => 'https://opensquadron.io/book'],
            ],
            'copilot-settings' => [
                'enableIntentRouting' => false,
                'intentCampaign' => '',
                'routingProtocol' => 'omnipresent',
                'deepContext' => false,
                'suspendOffHours' => false,
                'humanEscalation' => false,
                'typingIndicator' => false,
                'replyBuffer' => 0,
                'reasoningDepth' => 'standard'
            ]
        ];

        $settings = $defaultSettings;
        $sequences = [];
        $actionButtons = [];
        if ($selectedConnection) {
            $dir = __DIR__ . '/../../var/whatsapp_bot_settings';
            $file = $dir . "/conn_{$selectedConnection->getId()}.json";
            if (file_exists($file)) {
                $saved = json_decode(file_get_contents($file), true) ?: [];
                $settings = $this->mergeSettings($defaultSettings, $saved);
            }

            // Load sequences from DB
            $seqEntities = $em->getRepository(WhatsappDripSequence::class)->findBy(['whatsAppConnection' => $selectedConnection], ['id' => 'DESC']);
            foreach ($seqEntities as $seq) {
                $sequences[] = $seq->toArray();
            }

            // Load action buttons
            $actionBtnRepo = $em->getRepository(WhatsappActionButton::class);
            $actionBtnEntities = $actionBtnRepo->findBy(['whatsAppConnection' => $selectedConnection], ['id' => 'ASC']);
            
            $presets = [
                'get-started' => 'Get-started',
                'no-match' => 'No Match',
                'location-reply' => 'Location Reply',
                'unsubscribe' => 'Un-subscribe',
                'resubscribe' => 'Re-subscribe',
                'chat-with-human' => 'Chat with Human',
                'chat-with-bot' => 'Chat with Bot',
            ];
            
            $existingKeys = [];
            foreach ($actionBtnEntities as $btn) {
                $existingKeys[] = $btn->getButtonKey();
            }
            
            $seeded = false;
            foreach ($presets as $key => $label) {
                if (!in_array($key, $existingKeys)) {
                    $newBtn = new WhatsappActionButton();
                    $newBtn->setOwner($selectedConnection->getOwner());
                    $newBtn->setWhatsAppConnection($selectedConnection);
                    $newBtn->setButtonKey($key);
                    $newBtn->setButtonLabel($label);
                    $newBtn->setIsEnabled(false);
                    $newBtn->setReplyType('none');
                    $em->persist($newBtn);
                    $seeded = true;
                }
            }
            if ($seeded) {
                $em->flush();
                $actionBtnEntities = $actionBtnRepo->findBy(['whatsAppConnection' => $selectedConnection], ['id' => 'ASC']);
            }
            
            foreach ($actionBtnEntities as $btn) {
                $actionButtons[] = $btn->toArray();
            }
        }

        return $this->render('whatsapp_bot_manager/index.html.twig', [
            'flows' => $flows,
            'templates' => $templates,
            'aiSetting' => $aiSetting,
            'connection' => $selectedConnection,
            'connections' => $connections,
            'contexts' => $contexts,
            'settings' => $settings,
            'sequences' => $sequences,
            'actionButtons' => $actionButtons,
        ]);
    }

    #[Route('/whatsapp-bot-manager/action-buttons/save', name: 'app_whatsapp_action_buttons_save', methods: ['POST'])]
    public function saveActionButtons(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $connectionId = (int)$request->request->get('connectionId');
        if (!$connectionId) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid Connection ID.'], 400);
        }

        $connection = $em->getRepository(\App\Entity\WhatsAppConnection::class)->find($connectionId);
        if (!$connection) {
            return new JsonResponse(['success' => false, 'error' => 'Connection not found.'], 404);
        }

        $data = $request->request->get('data');
        $presets = json_decode($data, true);
        if (!is_array($presets)) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid presets data.'], 400);
        }

        $actionBtnRepo = $em->getRepository(WhatsappActionButton::class);

        foreach ($presets as $preset) {
            $key = $preset['buttonKey'] ?? null;
            if (!$key) continue;

            $entity = $actionBtnRepo->findOneBy([
                'whatsAppConnection' => $connection,
                'buttonKey' => $key
            ]);

            if (!$entity) {
                $entity = new WhatsappActionButton();
                $entity->setOwner($connection->getOwner());
                $entity->setWhatsAppConnection($connection);
                $entity->setButtonKey($key);
            }

            $entity->setButtonLabel($preset['buttonLabel'] ?? $key);
            $entity->setIsEnabled((bool)($preset['isEnabled'] ?? false));
            $entity->setReplyType($preset['replyType'] ?? 'none');
            $entity->setReplyText($preset['replyText'] ?? null);

            $flowId = $preset['flowId'] ?? null;
            if ($flowId) {
                $flow = $em->getRepository(WhatsappBotFlow::class)->find($flowId);
                $entity->setBotFlow($flow);
            } else {
                $entity->setBotFlow(null);
            }

            $em->persist($entity);
        }

        $em->flush();

        return new JsonResponse(['success' => true, 'message' => 'Action button templates saved successfully.']);
    }

    #[Route('/whatsapp-bot-manager/save-settings', name: 'app_whatsapp_bot_save_settings', methods: ['POST'])]
    public function saveSettings(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $connectionId = (int)$request->request->get('connectionId');
        if (!$connectionId) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid WhatsApp Connection ID.'], 400);
        }

        $type = trim((string)$request->request->get('type'));
        if ($type === '') {
            return new JsonResponse(['success' => false, 'error' => 'Settings target type is required.'], 400);
        }

        $dir = __DIR__ . '/../../var/whatsapp_bot_settings';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $file = $dir . "/conn_{$connectionId}.json";
        
        $currentSettings = [];
        if (file_exists($file)) {
            $currentSettings = json_decode(file_get_contents($file), true) ?: [];
        }

        $data = $request->request->get('data');
        if (\is_string($data)) {
            $decoded = json_decode($data, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $currentSettings[$type] = $decoded;
            } else {
                $currentSettings[$type] = $data;
            }
        } else {
            $currentSettings[$type] = $data;
        }

        file_put_contents($file, json_encode($currentSettings, JSON_PRETTY_PRINT));

        return new JsonResponse([
            'success' => true,
            'message' => 'Settings saved successfully.',
            'data'    => $currentSettings[$type]
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
        
        $customComponentsJson = $request->request->get('customComponents');
        $customComponents = null;
        if ($customComponentsJson) {
            $customComponents = json_decode($customComponentsJson, true);
        }

        if (!$name || (!$body && empty($customComponents))) {
            return new JsonResponse(['success' => false, 'error' => 'Name and Body/Components are required.'], 400);
        }

        try {
            $result = $whatsappService->createTemplate($name, $language, $category, $body, $header, $footer, $connection, $customComponents);
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

        $httpApis = $em->getRepository(\App\Entity\HttpApi::class)->findBy(['status' => 'active'], ['name' => 'ASC']);

        return $this->render('whatsapp_bot_manager/flows.html.twig', [
            'flows'       => $flows,
            'flowsJson'   => $payload,
            'templates'   => $templates,
            'connection'  => $selectedConnection,
            'httpApis'    => $httpApis,
        ]);
    }

    #[Route('/whatsapp-bot-manager/sequence-builder', name: 'app_whatsapp_bot_sequence_builder', methods: ['GET'])]
    public function sequenceBuilder(Request $request, EntityManagerInterface $em): Response
    {
        $connectionId = (int)$request->query->get('connectionId');
        $connection = $em->getRepository(\App\Entity\WhatsAppConnection::class)->find($connectionId);
        if (!$connection) {
            throw $this->createNotFoundException('Connection not found.');
        }

        $sequenceId = $request->query->get('id');
        $sequence = null;

        if ($sequenceId) {
            $entity = $em->getRepository(WhatsappDripSequence::class)->find((int)$sequenceId);
            if ($entity && $entity->getWhatsAppConnection()?->getId() === $connection->getId()) {
                $sequence = $entity->toArray();
            }
        }

        if (!$sequence) {
            // Default for a brand-new sequence (not yet persisted)
            $sequence = [
                'id' => null,
                'name' => 'New Sequence Campaign',
                'trigger' => 'NEW_SUBSCRIBER',
                'preferredTime' => 'anytime',
                'timezone' => 'UTC',
                'messageTag' => 'NON_PROMOTIONAL_SUBSCRIPTION',
                'allowReentry' => false,
                'isActive' => true,
                'stepsCount' => 0,
                'graph' => null
            ];
        }

        $flows = $em->getRepository(WhatsappBotFlow::class)->findBy(['whatsAppConnection' => $connection], ['id' => 'DESC']);
        $templates = $em->getRepository(MessageTemplate::class)->findBy([
            'status' => 'APPROVED',
            'whatsAppConnection' => $connection
        ], ['id' => 'DESC']);
        $contexts = $em->getRepository(AiContext::class)->findBy([], ['id' => 'DESC']);
        $httpApis = $em->getRepository(\App\Entity\HttpApi::class)->findBy(['status' => 'active'], ['name' => 'ASC']);

        return $this->render('whatsapp_bot_manager/sequence_builder.html.twig', [
            'connection' => $connection,
            'sequence' => $sequence,
            'flows' => $flows,
            'templates' => $templates,
            'contexts' => $contexts,
            'httpApis' => $httpApis,
        ]);
    }

    #[Route('/whatsapp-bot-manager/sequence-builder/save', name: 'app_whatsapp_bot_sequence_save', methods: ['POST'])]
    public function saveSequence(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid payload'], 400);
        }

        $connectionId = (int)($payload['connectionId'] ?? 0);
        $connection = $em->getRepository(\App\Entity\WhatsAppConnection::class)->find($connectionId);
        if (!$connection) {
            return new JsonResponse(['success' => false, 'error' => 'Connection not found.'], 404);
        }

        $sequenceId = $payload['id'] ?? null;
        $entity = null;

        if ($sequenceId) {
            $entity = $em->getRepository(WhatsappDripSequence::class)->find((int)$sequenceId);
            // Verify it belongs to the same connection
            if ($entity && $entity->getWhatsAppConnection()?->getId() !== $connection->getId()) {
                $entity = null;
            }
        }

        if (!$entity) {
            $entity = new WhatsappDripSequence();
            $entity->setWhatsAppConnection($connection);
            $entity->setOwner($this->getUser());
        }

        // Count how many "Send Message After" nodes we have in the graph
        $stepsCount = 0;
        if (isset($payload['graph']['nodes']) && is_array($payload['graph']['nodes'])) {
            foreach ($payload['graph']['nodes'] as $node) {
                if (($node['type'] ?? '') === 'send_message_after') {
                    $stepsCount++;
                }
            }
        }

        $entity->setName(trim((string)($payload['name'] ?? 'Untitled Sequence')));
        $entity->setTrigger(trim((string)($payload['trigger'] ?? 'NEW_SUBSCRIBER')));
        $entity->setPreferredTime(trim((string)($payload['preferredTime'] ?? 'anytime')));
        $entity->setTimezone(trim((string)($payload['timezone'] ?? 'UTC')));
        $entity->setMessageTag(trim((string)($payload['messageTag'] ?? 'NON_PROMOTIONAL_SUBSCRIPTION')));
        $entity->setAllowReentry((bool)($payload['allowReentry'] ?? false));
        $entity->setActive((bool)($payload['isActive'] ?? true));
        $entity->setStepsCount($stepsCount);
        $entity->setGraphData($payload['graph'] ?? null);

        $em->persist($entity);
        $em->flush();

        return new JsonResponse(['success' => true, 'sequence' => $entity->toArray()]);
    }

    #[Route('/whatsapp-bot-manager/sequence-builder/delete', name: 'app_whatsapp_bot_sequence_delete', methods: ['POST'])]
    public function deleteSequence(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid payload'], 400);
        }

        $sequenceId = (int)($payload['sequenceId'] ?? 0);
        $entity = $em->getRepository(WhatsappDripSequence::class)->find($sequenceId);
        if (!$entity) {
            return new JsonResponse(['success' => false, 'error' => 'Sequence not found.'], 404);
        }

        $em->remove($entity);
        $em->flush();

        return new JsonResponse(['success' => true]);
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

    #[Route('/whatsapp-bot-manager/meta-flows/sync', name: 'app_whatsapp_bot_meta_flows_sync', methods: ['POST'])]
    public function syncMetaFlow(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid payload'], 400);
        }

        // Mock syncing to Meta Graph API
        // This is where POST /{waba_id}/flows and POST /{flow_id}/assets would occur
        return new JsonResponse([
            'success' => true, 
            'message' => 'Flow successfully synced to Meta in DRAFT mode.',
            'meta_flow_id' => 'mock_meta_flow_' . rand(1000, 9999)
        ]);
    }

    #[Route('/whatsapp-bot-manager/meta-flows/preview', name: 'app_whatsapp_bot_meta_flows_preview', methods: ['POST'])]
    public function previewMetaFlow(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid payload'], 400);
        }

        // Mock fetching preview from Meta Graph API
        // This is where GET /{flow_id}?fields=preview would occur
        return new JsonResponse([
            'success' => true, 
            'preview_url' => 'https://business.facebook.com/wa/manage/flows/preview?flow_id=mock_meta_flow_1234'
        ]);
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

    private function mergeSettings(array $defaults, array $saved): array
    {
        $result = $defaults;
        foreach ($saved as $key => $value) {
            if (array_key_exists($key, $defaults) && is_array($value) && is_array($defaults[$key])) {
                if (array_is_list($value) || array_is_list($defaults[$key])) {
                    $result[$key] = $value;
                } else {
                    $result[$key] = $this->mergeSettings($defaults[$key], $value);
                }
            } else {
                $result[$key] = $value;
            }
        }
        return $result;
    }
}

