<?php

namespace App\Controller;

use App\Entity\FacebookBotFlow;
use App\Entity\FacebookConnection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use App\Entity\AiContext;
use App\Service\FacebookService;

class FacebookBotManagerController extends AbstractController
{
    #[Route('/facebook-bot-manager', name: 'app_facebook_bot_manager')]
    public function index(Request $request, EntityManagerInterface $em): Response
    {
        $connections = $em->getRepository(FacebookConnection::class)->findBy([], ['id' => 'DESC']);
        
        $selectedConnectionId = $request->query->get('connectionId');
        $selectedConnection = null;
        if ($selectedConnectionId) {
            $selectedConnection = $em->getRepository(FacebookConnection::class)->find($selectedConnectionId);
        }
        if (!$selectedConnection && !empty($connections)) {
            $selectedConnection = $connections[0];
        }

        $flows = [];
        $settings = [];
        
        // Default realistic settings to populate a fully featured state instantly on first load
        $defaultSettings = [
            'broadcast-template' => [
                ['name' => 'welcome_offer_rcn', 'category' => 'UTILITY', 'language' => 'en_US', 'status' => 'APPROVED'],
                ['name' => 'abandoned_cart_reminder', 'category' => 'MARKETING', 'language' => 'en_US', 'status' => 'PENDING'],
            ],
            'postbacks' => [
                ['name' => 'MainMenu', 'payload' => 'MAIN_MENU_TRIGGER', 'action' => 'Open Main Menu'],
                ['name' => 'TalkToHuman', 'payload' => 'ESCALATE_HUMAN_TRIGGER', 'action' => 'Notify Agent'],
            ],
            'growth-widgets' => [
                'widgetType' => 'checkbox',
                'color' => '#06b6d4',
                'size' => 'large',
                'domains' => 'opensquadron.io',
            ],
            'sequences' => [
                ['name' => 'Day 1 Warmup', 'delay' => '24 hours', 'isActive' => true],
                ['name' => 'Day 3 Promo Offer', 'delay' => '72 hours', 'isActive' => false],
            ],
            'user-inputs' => [
                ['fieldName' => 'user_email', 'dataType' => 'EMAIL', 'prompt' => 'Please share your email address:'],
                ['fieldName' => 'user_phone', 'dataType' => 'PHONE', 'prompt' => 'Please provide your mobile number:'],
            ],
            'persistent-menu' => [
                ['title' => '🏠 Home Portal', 'type' => 'postback', 'payload' => 'MAIN_MENU_TRIGGER'],
                ['title' => '🛍️ View Products', 'type' => 'web_url', 'url' => 'https://opensquadron.io/shop'],
                ['title' => '💬 Contact Support', 'type' => 'postback', 'payload' => 'ESCALATE_HUMAN_TRIGGER'],
            ],
            'rcn-notifications' => [
                ['title' => 'Weekly Product Updates', 'frequency' => 'WEEKLY', 'payload' => 'WEEKLY_UPDATE_RCN'],
                ['title' => 'Monthly Exclusive Offers', 'frequency' => 'MONTHLY', 'payload' => 'MONTHLY_DEALS_RCN'],
            ],
            'whitelisted-domains' => [
                'domains' => "https://opensquadron.io\nhttps://messenger.opensquadron.io"
            ],
            'api-connectors' => [
                ['name' => 'HubSpot CRM Sync', 'url' => 'https://api.hubspot.com/contacts/v1/contact', 'method' => 'POST'],
            ],
            'outbound-webhooks' => [
                'url' => 'https://webhook.site/#!/your-endpoint-url',
                'events' => ['message_delivered', 'message_read', 'postback_received']
            ],
            'action-buttons' => [
                ['label' => '📞 Call Main Office', 'type' => 'phone_number', 'value' => '+15550199'],
                ['label' => '🌐 Open Website', 'type' => 'web_url', 'value' => 'https://opensquadron.io'],
            ],
            'welcome-screen' => [
                'greetingText' => 'Welcome to OpenSquadron! Tap Get Started to explore our automated integrations.',
                'getStartedPayload' => 'WELCOME_GET_STARTED_TRIGGER',
                'showGreeting' => true
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

        if ($selectedConnection) {
            $flows = $em->getRepository(FacebookBotFlow::class)->findBy(['facebookConnection' => $selectedConnection], ['id' => 'DESC']);
            
            // Read saved settings
            $dir = __DIR__ . '/../../var/facebook_bot_settings';
            $file = $dir . "/conn_{$selectedConnection->getId()}.json";
            if (file_exists($file)) {
                $saved = json_decode(file_get_contents($file), true) ?: [];
                $settings = array_replace_recursive($defaultSettings, $saved);
            } else {
                $settings = $defaultSettings;
            }
        } else {
            $settings = $defaultSettings;
        }

        $contexts = $em->getRepository(AiContext::class)->findBy([], ['id' => 'DESC']);

        return $this->render('facebook_bot_manager/index.html.twig', [
            'flows'       => $flows,
            'connection'  => $selectedConnection,
            'connections' => $connections,
            'contexts'    => $contexts,
            'settings'    => $settings,
        ]);
    }

    #[Route('/facebook-bot-manager/save-settings', name: 'app_facebook_bot_save_settings', methods: ['POST'])]
    public function saveSettings(Request $request): JsonResponse
    {
        $connectionId = (int)$request->request->get('connectionId');
        if (!$connectionId) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid Facebook Connection ID.'], 400);
        }

        $type = trim((string)$request->request->get('type'));
        if ($type === '') {
            return new JsonResponse(['success' => false, 'error' => 'Settings target type is required.'], 400);
        }

        $dir = __DIR__ . '/../../var/facebook_bot_settings';
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

    #[Route('/facebook-bot-manager/flows', name: 'app_facebook_bot_flows', methods: ['GET'])]
    public function flows(Request $request, EntityManagerInterface $em): Response
    {
        $connections = $em->getRepository(FacebookConnection::class)->findBy([], ['id' => 'DESC']);
        
        $selectedConnectionId = $request->query->get('connectionId');
        $selectedConnection = null;
        if ($selectedConnectionId) {
            $selectedConnection = $em->getRepository(FacebookConnection::class)->find($selectedConnectionId);
        }
        if (!$selectedConnection && !empty($connections)) {
            $selectedConnection = $connections[0];
        }

        $flows = [];
        $defaultTemplates = [
            ['name' => 'welcome_offer_rcn', 'language' => 'en_US'],
            ['name' => 'abandoned_cart_reminder', 'language' => 'en_US'],
        ];
        $templates = $defaultTemplates;

        if ($selectedConnection) {
            $flows = $em->getRepository(FacebookBotFlow::class)->findBy(['facebookConnection' => $selectedConnection], ['id' => 'DESC']);
            
            $dir = __DIR__ . '/../../var/facebook_bot_settings';
            $file = $dir . "/conn_{$selectedConnection->getId()}.json";
            if (file_exists($file)) {
                $saved = json_decode(file_get_contents($file), true) ?: [];
                if (isset($saved['broadcast-template']) && \is_array($saved['broadcast-template'])) {
                    $templates = $saved['broadcast-template'];
                }
            }
        }

        $payload = array_map(static fn (FacebookBotFlow $f) => self::flowToArray($f), $flows);

        $httpApis = $em->getRepository(\App\Entity\HttpApi::class)->findBy(['status' => 'active'], ['name' => 'ASC']);

        return $this->render('facebook_bot_manager/flows.html.twig', [
            'flows'       => $flows,
            'flowsJson'   => $payload,
            'connection'  => $selectedConnection,
            'connections' => $connections,
            'templates'   => $templates,
            'httpApis'    => $httpApis,
        ]);
    }

    #[Route('/facebook-bot-manager/flows/save', name: 'app_facebook_bot_flows_save', methods: ['POST'])]
    public function saveFlow(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid payload'], 400);
        }

        $id        = isset($payload['id']) ? (int)$payload['id'] : null;
        $name      = trim((string)($payload['name'] ?? ''));
        $keywords  = $this->normaliseKeywords($payload['keywords'] ?? '');
        $matchMode = (string)($payload['matchMode'] ?? FacebookBotFlow::MATCH_EXACT);
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

        $flow = $id ? $em->getRepository(FacebookBotFlow::class)->find($id) : null;
        if (!$flow) {
            $flow = new FacebookBotFlow();
        }

        // Get selected connection
        $connectionId = isset($payload['connectionId']) ? (int)$payload['connectionId'] : (int)$request->query->get('connectionId');
        $connection = null;
        if ($connectionId) {
            $connection = $em->getRepository(FacebookConnection::class)->find($connectionId);
        }
        if (!$connection) {
            // Default to the first connection
            $connections = $em->getRepository(FacebookConnection::class)->findBy([], ['id' => 'DESC']);
            $connection = $connections[0] ?? null;
        }

        $flow->setFacebookConnection($connection);
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

    #[Route('/facebook-bot-manager/flows/{id}/delete', name: 'app_facebook_bot_flows_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function deleteFlow(int $id, EntityManagerInterface $em): JsonResponse
    {
        $flow = $em->getRepository(FacebookBotFlow::class)->find($id);
        if (!$flow) {
            return new JsonResponse(['success' => false, 'error' => 'Flow not found.'], 404);
        }
        $em->remove($flow);
        $em->flush();

        return new JsonResponse(['success' => true]);
    }

    #[Route('/facebook-bot-manager/flows/{id}/toggle', name: 'app_facebook_bot_flows_toggle', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function toggleFlow(int $id, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $flow = $em->getRepository(FacebookBotFlow::class)->find($id);
        if (!$flow) {
            return new JsonResponse(['success' => false, 'error' => 'Flow not found.'], 404);
        }

        $payload = json_decode($request->getContent(), true);
        $isActive = isset($payload['isActive']) ? (bool)$payload['isActive'] : !$flow->isActive();

        $flow->setActive($isActive);
        $em->flush();

        return new JsonResponse(['success' => true, 'isActive' => $flow->isActive()]);
    }

    #[Route('/facebook-bot-manager/flows/{id}/clone', name: 'app_facebook_bot_flows_clone', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function cloneFlow(int $id, EntityManagerInterface $em): JsonResponse
    {
        $flow = $em->getRepository(FacebookBotFlow::class)->find($id);
        if (!$flow) {
            return new JsonResponse(['success' => false, 'error' => 'Flow not found.'], 404);
        }

        $clone = new FacebookBotFlow();
        $clone->setName($flow->getName() . ' (Copy)');
        $clone->setTriggerKeyword($flow->getTriggerKeyword());
        $clone->setMatchMode($flow->getMatchMode());
        $clone->setActive($flow->isActive());
        $clone->setFlowData($flow->getFlowData());
        $clone->setFacebookConnection($flow->getFacebookConnection());

        $em->persist($clone);
        $em->flush();

        return new JsonResponse(['success' => true]);
    }

    #[Route('/facebook-bot-manager/flows/{id}/export', name: 'app_facebook_bot_flows_export', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function exportFlow(int $id, EntityManagerInterface $em): Response
    {
        $flow = $em->getRepository(FacebookBotFlow::class)->find($id);
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

    #[Route('/facebook-bot-manager/ai-settings/agent', name: 'app_facebook_bot_ai_agent_save', methods: ['POST'])]
    public function saveAiAgentSettings(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $connectionId = $request->request->get('connectionId');
        $connection = null;
        if ($connectionId) {
            $connection = $em->getRepository(FacebookConnection::class)->find($connectionId);
        }
        if (!$connection) {
            $connection = $em->getRepository(FacebookConnection::class)->findOneBy([]);
        }

        if (!$connection) {
            return new JsonResponse(['success' => false, 'error' => 'No Facebook Connection found.']);
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
            $context = $em->getRepository(AiContext::class)->find($activeContextId);
            $connection->setActiveContext($context);
        } else {
            $connection->setActiveContext(null);
        }

        $em->persist($connection);
        $em->flush();

        return new JsonResponse([
            'success' => true,
            'message' => 'Facebook AI Agent Settings saved successfully.',
        ]);
    }

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
    private static function flowToArray(FacebookBotFlow $flow): array
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

    // ───────────────────────── Social Posting Endpoints ─────────────────────────

    #[Route('/facebook-bot-manager/posts/list', name: 'app_facebook_bot_posts_list', methods: ['GET'])]
    public function listPosts(Request $request): JsonResponse
    {
        $connectionId = (int)$request->query->get('connectionId');
        if (!$connectionId) {
            return new JsonResponse(['success' => false, 'error' => 'Connection ID is required.'], 400);
        }

        $dir = __DIR__ . '/../../var/facebook_posts';
        $file = $dir . "/conn_{$connectionId}.json";
        $posts = [];

        if (file_exists($file)) {
            $posts = json_decode(file_get_contents($file), true) ?: [];
        }

        return new JsonResponse(['success' => true, 'posts' => $posts]);
    }

    #[Route('/facebook-bot-manager/posts/save', name: 'app_facebook_bot_posts_save', methods: ['POST'])]
    public function savePost(Request $request, EntityManagerInterface $em, FacebookService $facebookService): JsonResponse
    {
        $connectionId = (int)$request->request->get('connectionId');
        if (!$connectionId) {
            return new JsonResponse(['success' => false, 'error' => 'Connection ID is required.'], 400);
        }

        $connection = $em->getRepository(FacebookConnection::class)->find($connectionId);
        if (!$connection) {
            return new JsonResponse(['success' => false, 'error' => 'Connection not found.'], 404);
        }

        $id = $request->request->get('id');
        $type = $request->request->get('type', 'multimedia');
        $message = trim((string)$request->request->get('message', ''));
        $link = trim((string)$request->request->get('link', ''));
        $mediaType = $request->request->get('mediaType', 'none');
        $mediaUrl = trim((string)$request->request->get('mediaUrl', ''));
        $ctaType = trim((string)$request->request->get('ctaType', ''));
        $slidesJson = $request->request->get('slides', '[]');
        $slides = json_decode($slidesJson, true) ?: [];
        $publishNow = filter_var($request->request->get('publishNow'), FILTER_VALIDATE_BOOLEAN);

        $dir = __DIR__ . '/../../var/facebook_posts';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $file = $dir . "/conn_{$connectionId}.json";

        $posts = [];
        if (file_exists($file)) {
            $posts = json_decode(file_get_contents($file), true) ?: [];
        }

        $postIndex = -1;
        if ($id) {
            foreach ($posts as $idx => $p) {
                if ($p['id'] == $id) {
                    $postIndex = $idx;
                    break;
                }
            }
        }

        $post = $postIndex >= 0 ? $posts[$postIndex] : [
            'id' => uniqid('post_'),
            'createdAt' => (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
        ];

        $post['type'] = $type;
        $post['message'] = $message;
        $post['link'] = $link;
        $post['mediaType'] = $mediaType;
        $post['mediaUrl'] = $mediaUrl;
        $post['ctaType'] = $ctaType;
        $post['slides'] = $slides;
        $post['status'] = $publishNow ? 'published' : 'draft';
        $post['fbPostId'] = null;
        $post['errorMessage'] = null;

        if ($publishNow) {
            try {
                $result = null;
                if ($type === 'multimedia') {
                    if ($mediaType === 'image' && $mediaUrl !== '') {
                        $result = $facebookService->publishPhotoPost($connection, $mediaUrl, $message);
                    } elseif ($mediaType === 'video' && $mediaUrl !== '') {
                        $result = $facebookService->publishVideoPost($connection, $mediaUrl, $message);
                    } else {
                        $result = $facebookService->publishFeedPost($connection, $message, $link);
                    }
                } elseif ($type === 'cta') {
                    $result = $facebookService->publishCtaPost($connection, $message, $link, $ctaType);
                } elseif ($type === 'carousel') {
                    $result = $facebookService->publishCarouselPost($connection, $message, $slides);
                }

                if ($result && isset($result['id'])) {
                    $post['fbPostId'] = $result['id'];
                } elseif ($result && isset($result['post_id'])) {
                    $post['fbPostId'] = $result['post_id'];
                }
            } catch (\Exception $e) {
                $post['status'] = 'failed';
                $post['errorMessage'] = $e->getMessage();
            }
        }

        if ($postIndex >= 0) {
            $posts[$postIndex] = $post;
        } else {
            $posts[] = $post;
        }

        file_put_contents($file, json_encode($posts, JSON_PRETTY_PRINT));

        return new JsonResponse(['success' => true, 'post' => $post]);
    }

    #[Route('/facebook-bot-manager/posts/publish/{id}', name: 'app_facebook_bot_posts_publish', methods: ['POST'])]
    public function publishPost(string $id, Request $request, EntityManagerInterface $em, FacebookService $facebookService): JsonResponse
    {
        $connectionId = (int)$request->request->get('connectionId');
        if (!$connectionId) {
            return new JsonResponse(['success' => false, 'error' => 'Connection ID is required.'], 400);
        }

        $connection = $em->getRepository(FacebookConnection::class)->find($connectionId);
        if (!$connection) {
            return new JsonResponse(['success' => false, 'error' => 'Connection not found.'], 404);
        }

        $dir = __DIR__ . '/../../var/facebook_posts';
        $file = $dir . "/conn_{$connectionId}.json";

        if (!file_exists($file)) {
            return new JsonResponse(['success' => false, 'error' => 'Post log not found.'], 404);
        }

        $posts = json_decode(file_get_contents($file), true) ?: [];
        $postIndex = -1;
        foreach ($posts as $idx => $p) {
            if ($p['id'] === $id) {
                $postIndex = $idx;
                break;
            }
        }

        if ($postIndex === -1) {
            return new JsonResponse(['success' => false, 'error' => 'Post not found in log.'], 404);
        }

        $post = $posts[$postIndex];
        $type = $post['type'] ?? 'multimedia';
        $message = $post['message'] ?? '';
        $link = $post['link'] ?? '';
        $mediaType = $post['mediaType'] ?? 'none';
        $mediaUrl = $post['mediaUrl'] ?? '';
        $ctaType = $post['ctaType'] ?? '';
        $slides = $post['slides'] ?? [];

        try {
            $result = null;
            if ($type === 'multimedia') {
                if ($mediaType === 'image' && $mediaUrl !== '') {
                    $result = $facebookService->publishPhotoPost($connection, $mediaUrl, $message);
                } elseif ($mediaType === 'video' && $mediaUrl !== '') {
                    $result = $facebookService->publishVideoPost($connection, $mediaUrl, $message);
                } else {
                    $result = $facebookService->publishFeedPost($connection, $message, $link);
                }
            } elseif ($type === 'cta') {
                $result = $facebookService->publishCtaPost($connection, $message, $link, $ctaType);
            } elseif ($type === 'carousel') {
                $result = $facebookService->publishCarouselPost($connection, $message, $slides);
            }

            $post['status'] = 'published';
            $post['errorMessage'] = null;
            if ($result && isset($result['id'])) {
                $post['fbPostId'] = $result['id'];
            } elseif ($result && isset($result['post_id'])) {
                $post['fbPostId'] = $result['post_id'];
            }
        } catch (\Exception $e) {
            $post['status'] = 'failed';
            $post['errorMessage'] = $e->getMessage();
        }

        $posts[$postIndex] = $post;
        file_put_contents($file, json_encode($posts, JSON_PRETTY_PRINT));

        return new JsonResponse(['success' => true, 'post' => $post]);
    }

    #[Route('/facebook-bot-manager/posts/delete/{id}', name: 'app_facebook_bot_posts_delete', methods: ['POST'])]
    public function deletePost(string $id, Request $request): JsonResponse
    {
        $connectionId = (int)$request->request->get('connectionId');
        if (!$connectionId) {
            return new JsonResponse(['success' => false, 'error' => 'Connection ID is required.'], 400);
        }

        $dir = __DIR__ . '/../../var/facebook_posts';
        $file = $dir . "/conn_{$connectionId}.json";

        if (!file_exists($file)) {
            return new JsonResponse(['success' => false, 'error' => 'Post log not found.'], 404);
        }

        $posts = json_decode(file_get_contents($file), true) ?: [];
        $updatedPosts = [];
        $found = false;

        foreach ($posts as $p) {
            if ($p['id'] === $id) {
                $found = true;
                continue;
            }
            $updatedPosts[] = $p;
        }

        if (!$found) {
            return new JsonResponse(['success' => false, 'error' => 'Post not found in log.'], 404);
        }

        file_put_contents($file, json_encode($updatedPosts, JSON_PRETTY_PRINT));

        return new JsonResponse(['success' => true]);
    }
}
