<?php

namespace App\Controller;

use App\Entity\InstagramBotFlow;
use App\Entity\InstagramConnection;
use App\Entity\InstagramDripSequence;
use App\Entity\InstagramActionButton;
use App\Entity\Admin;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use App\Entity\AiContext;
use App\Service\InstagramService;
use App\Service\SubscriptionUsageService;
use App\Security\Voter\TeamPermissionVoter;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(TeamPermissionVoter::PERM_INSTAGRAM_MANAGE)]
class InstagramBotManagerController extends AbstractController
{
    public function __construct(private SubscriptionUsageService $usageService) {}
    #[Route('/Instagram-bot-manager', name: 'app_instagram_bot_manager')]
    public function index(Request $request, EntityManagerInterface $em): Response
    {
        /** @var Admin $user */
        $user = $this->getUser();
        if (!$this->usageService->hasModuleAccess($user, 'Instagram')) {
            $this->addFlash('error', 'Your subscription plan does not include access to Instagram Bot Manager.');
            return $this->redirectToRoute('app_dashboard');
        }

        $connections = $em->getRepository(InstagramConnection::class)->findBy([], ['id' => 'DESC']);
        
        $selectedConnectionId = $request->query->get('connectionId');
        $selectedConnection = null;
        if ($selectedConnectionId) {
            $selectedConnection = $em->getRepository(InstagramConnection::class)->find($selectedConnectionId);
        }
        if (!$selectedConnection && !empty($connections)) {
            $selectedConnection = $connections[0];
        }

        $flows = [];
        $settings = [];
        
        // Default realistic settings to populate a fully featured state instantly on first load
        $defaultSettings = [
            'broadcast-template' => [
                ['name' => 'welcome_offer_mm', 'category' => 'UTILITY', 'language' => 'en_US', 'status' => 'APPROVED'],
                ['name' => 'abandoned_cart_reminder', 'category' => 'MARKETING', 'language' => 'en_US', 'status' => 'PENDING'],
            ],
            'postbacks' => [
                ['id' => 'pb_main_menu_trg', 'name' => 'MainMenu', 'payload' => 'MAIN_MENU_TRIGGER', 'action' => 'Open Main Menu', 'linkedFlowId' => null, 'updatedAt' => '2026-01-01 00:00:00'],
                ['id' => 'pb_escalate_hmn', 'name' => 'TalkToHuman', 'payload' => 'ESCALATE_HUMAN_TRIGGER', 'action' => 'Notify Agent', 'linkedFlowId' => null, 'updatedAt' => '2026-01-01 00:00:00'],
            ],
            'growth-widgets' => [
                'widgetType' => 'checkbox',
                'color' => '#06b6d4',
                'size' => 'large',
                'domains' => 'opensquadron.io',
            ],
            'sequences' => [],
            'user-inputs' => [
                ['fieldName' => 'user_email', 'dataType' => 'EMAIL', 'prompt' => 'Please share your email address:'],
                ['fieldName' => 'user_phone', 'dataType' => 'PHONE', 'prompt' => 'Please provide your mobile number:'],
            ],
            'persistent-menu' => [
                ['title' => 'Ã°Å¸ÂÂ  Home Portal', 'type' => 'postback', 'payload' => 'MAIN_MENU_TRIGGER'],
                ['title' => 'Ã°Å¸â€ºÂÃ¯Â¸Â View Products', 'type' => 'web_url', 'url' => 'https://opensquadron.io/shop'],
                ['title' => 'Ã°Å¸â€™Â¬ Contact Support', 'type' => 'postback', 'payload' => 'ESCALATE_HUMAN_TRIGGER'],
            ],
            'marketing-messages' => [
                ['id' => 'mm_weekly_updates', 'title' => 'Weekly Product Updates', 'frequency' => 'WEEKLY', 'payload' => 'WEEKLY_UPDATE_MM', 'linkedPostbackId' => '', 'imageUrl' => '', 'typingIndicator' => false, 'delaySeconds' => 0, 'delayMinutes' => 0, 'budgetCents' => 5000, 'status' => 'draft', 'stats' => ['subscribers' => 0, 'sent' => 0, 'delivered' => 0, 'errors' => 0], 'updatedAt' => '2026-01-01 00:00:00'],
                ['id' => 'mm_monthly_deals', 'title' => 'Monthly Exclusive Offers', 'frequency' => 'MONTHLY', 'payload' => 'MONTHLY_DEALS_MM', 'linkedPostbackId' => '', 'imageUrl' => '', 'typingIndicator' => false, 'delaySeconds' => 0, 'delayMinutes' => 0, 'budgetCents' => 10000, 'status' => 'draft', 'stats' => ['subscribers' => 0, 'sent' => 0, 'delivered' => 0, 'errors' => 0], 'updatedAt' => '2026-01-01 00:00:00'],
            ],
            'rcn-notifications' => [
                ['title' => 'Weekly Newsletter Opt-in', 'frequency' => 'WEEKLY', 'payload' => 'WEEKLY_NEWSLETTER_RCN'],
                ['title' => 'Monthly Promotion Opt-in', 'frequency' => 'MONTHLY', 'payload' => 'MONTHLY_PROMO_RCN'],
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
                ['label' => 'Ã°Å¸â€œÅ¾ Call Main Office', 'type' => 'phone_number', 'value' => '+15550199'],
                ['label' => 'Ã°Å¸Å’Â Open Website', 'type' => 'web_url', 'value' => 'https://opensquadron.io'],
            ],
            'welcome-screen' => [
                'greetingText' => 'Welcome to OpenSquadron! Tap Get Started to explore our automated integrations.',
                'getStartedPayload' => 'WELCOME_GET_STARTED_TRIGGER',
                'showGreeting' => true,
                'getStartedStatus' => 'enabled',
                'iceBreakersStatus' => 'disabled',
                'iceBreakers' => []
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
            ],
            'comment-automation' => [
                'hideOrDelete' => 'hide',
                'offensiveKeywords' => '',
                'offensivePrivateReplyFlowId' => '',
                'sendReplyMultipleTimes' => false,
                'enableCommentReply' => true,
                'hideCommentAfterReply' => false,
                'automationMode' => 'generic',
                'campaignName' => '',
                'aiContextId' => '',
                'privateReplyFlowId' => '',
                'commentReplyText' => '',
                'filterMatchType' => 'exact',
                'filterWords' => ''
            ]
        ];

        $sequences = [];
        $actionButtons = [];
        if ($selectedConnection) {
            $flows = $em->getRepository(InstagramBotFlow::class)->findBy(['InstagramConnection' => $selectedConnection], ['id' => 'DESC']);
            
            // Read saved settings
            $saved = $selectedConnection->getBotSettings() ?: [];
            $settings = $this->mergeSettings($defaultSettings, $saved);

            // Load sequences from DB
            $seqEntities = $em->getRepository(InstagramDripSequence::class)->findBy(['InstagramConnection' => $selectedConnection], ['id' => 'DESC']);
            foreach ($seqEntities as $seq) {
                $sequences[] = $seq->toArray();
            }

            // Load action buttons
            $actionBtnRepo = $em->getRepository(InstagramActionButton::class);
            $actionBtnEntities = $actionBtnRepo->findBy(['InstagramConnection' => $selectedConnection], ['id' => 'ASC']);
            
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
                    $newBtn = new InstagramActionButton();
                    $newBtn->setOwner($selectedConnection->getOwner());
                    $newBtn->setInstagramConnection($selectedConnection);
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
                $actionBtnEntities = $actionBtnRepo->findBy(['InstagramConnection' => $selectedConnection], ['id' => 'ASC']);
            }
            
            foreach ($actionBtnEntities as $btn) {
                $actionButtons[] = $btn->toArray();
            }
        } else {
            $settings = $defaultSettings;
        }

        $contexts = $em->getRepository(AiContext::class)->findBy([], ['id' => 'DESC']);

        return $this->render('Instagram_bot_manager/index.html.twig', [
            'flows'         => $flows,
            'connection'    => $selectedConnection,
            'connections'   => $connections,
            'contexts'      => $contexts,
            'settings'      => $settings,
            'sequences'     => $sequences,
            'actionButtons' => $actionButtons,
        ]);
    }

    #[Route('/Instagram-bot-manager/action-buttons/save', name: 'app_instagram_action_buttons_save', methods: ['POST'])]
    public function saveActionButtons(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $connectionId = (int)$request->request->get('connectionId');
        if (!$connectionId) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid Connection ID.'], 400);
        }

        $connection = $em->getRepository(InstagramConnection::class)->find($connectionId);
        if (!$connection) {
            return new JsonResponse(['success' => false, 'error' => 'Connection not found.'], 404);
        }

        $data = $request->request->get('data');
        $presets = json_decode($data, true);
        if (!is_array($presets)) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid presets data.'], 400);
        }

        $actionBtnRepo = $em->getRepository(InstagramActionButton::class);

        foreach ($presets as $preset) {
            $key = $preset['buttonKey'] ?? null;
            if (!$key) continue;

            $entity = $actionBtnRepo->findOneBy([
                'InstagramConnection' => $connection,
                'buttonKey' => $key
            ]);

            if (!$entity) {
                $entity = new InstagramActionButton();
                $entity->setOwner($connection->getOwner());
                $entity->setInstagramConnection($connection);
                $entity->setButtonKey($key);
            }

            $entity->setButtonLabel($preset['buttonLabel'] ?? $key);
            $entity->setIsEnabled((bool)($preset['isEnabled'] ?? false));
            $entity->setReplyType($preset['replyType'] ?? 'none');
            $entity->setReplyText($preset['replyText'] ?? null);

            $flowId = $preset['flowId'] ?? null;
            if ($flowId) {
                $flow = $em->getRepository(InstagramBotFlow::class)->find($flowId);
                $entity->setBotFlow($flow);
            } else {
                $entity->setBotFlow(null);
            }

            $em->persist($entity);
        }

        $em->flush();

        return new JsonResponse(['success' => true, 'message' => 'Action button templates saved successfully.']);
    }

    #[Route('/Instagram-bot-manager/save-settings', name: 'app_instagram_bot_save_settings', methods: ['POST'])]
    public function saveSettings(
        Request $request,
        EntityManagerInterface $em,
        InstagramService $InstagramService
    ): JsonResponse {
        $connectionId = (int)$request->request->get('connectionId');
        if (!$connectionId) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid Instagram Connection ID.'], 400);
        }

        $connection = $em->getRepository(InstagramConnection::class)->find($connectionId);
        if (!$connection) {
            return new JsonResponse(['success' => false, 'error' => 'Connection not found.'], 404);
        }

        $type = trim((string)$request->request->get('type'));
        if ($type === '') {
            return new JsonResponse(['success' => false, 'error' => 'Settings target type is required.'], 400);
        }

        $data = $request->request->get('data');
        $decoded = \is_string($data) ? json_decode($data, true) : $data;
        if (\is_string($data) && json_last_error() !== JSON_ERROR_NONE) {
            $decoded = $data;
        }

        if ($type === 'comment-automation') {
            $automationRepo = $em->getRepository(\App\Entity\InstagramCommentAutomation::class);
            $automation = $automationRepo->findOneBy([
                'InstagramConnection' => $connection,
                'postId' => null
            ]);

            if (!$automation) {
                $automation = new \App\Entity\InstagramCommentAutomation();
                $automation->setInstagramConnection($connection);
                $automation->setOwner($connection->getOwner());
            }

            if (is_array($decoded)) {
                $automation->setCampaignName($decoded['campaignName'] ?? null);
                $automation->setAutomationMode($decoded['automationMode'] ?? 'generic');
                $automation->setEnableCommentReply((bool)($decoded['enableCommentReply'] ?? false));
                $automation->setHideOrDelete($decoded['hideOrDelete'] ?? null);
                $automation->setOffensiveKeywords($decoded['offensiveKeywords'] ?? null);
                $automation->setOffensivePrivateReplyFlow($decoded['offensivePrivateReplyFlowId'] ?? null);
                $automation->setSendReplyMultipleTimes((bool)($decoded['sendReplyMultipleTimes'] ?? false));
                $automation->setHideCommentAfterReply((bool)($decoded['hideCommentAfterReply'] ?? false));
                $automation->setAiContextId($decoded['aiContextId'] ?? null);
                
                $automation->setGenericCommentReply($decoded['commentReplyText'] ?? null);
                $automation->setGenericPrivateReply($decoded['privateReplyFlowId'] ?? null);

                $fallback = $decoded['fallbackSettings'] ?? [];
                $automation->setFallbackCommentReply($fallback['commentReplyText'] ?? null);
                $automation->setFallbackPrivateReply($fallback['privateReplyFlowId'] ?? null);

                // Handle rules
                foreach ($automation->getRules() as $existingRule) {
                    $automation->removeRule($existingRule);
                }

                $rules = $decoded['filterRules'] ?? [];
                if (is_array($rules)) {
                    foreach ($rules as $ruleData) {
                        $rule = new \App\Entity\InstagramCommentAutomationRule();
                        $rule->setFilterWords($ruleData['filterWords'] ?? null);
                        $rule->setFilterMatchType($ruleData['filterMatchType'] ?? 'exact');
                        $rule->setCommentReplyText($ruleData['commentReplyText'] ?? null);
                        $rule->setPrivateReplyFlowId($ruleData['privateReplyFlowId'] ?? null);
                        $automation->addRule($rule);
                    }
                }
            }

            $em->persist($automation);
            $em->flush();
        }

        $currentSettings = $connection->getBotSettings() ?: [];
        $currentSettings[$type] = $decoded;

        if ($type === 'persistent-menu') {
            if ($connection) {
                $menuItems = [];
                if (is_array($currentSettings['persistent-menu'])) {
                    foreach ($currentSettings['persistent-menu'] as $item) {
                        $cta = [
                            'title' => $item['title'] ?? '',
                            'type' => $item['type'] ?? 'postback'
                        ];
                        if ($cta['type'] === 'postback') {
                            $cta['payload'] = $item['payload'] ?? '';
                        } else {
                            $cta['url'] = $item['url'] ?? '';
                        }
                        $menuItems[] = $cta;
                    }
                }

                try {
                    if (empty($menuItems)) {
                        $InstagramService->deletePersistentMenu($connection);
                    } else {
                        $InstagramService->setPersistentMenu($connection, $menuItems);
                    }
                } catch (\Exception $e) {
                    return new JsonResponse([
                        'success' => false,
                        'error' => 'Saved locally but failed to update Instagram: ' . $e->getMessage()
                    ], 500);
                }
            }
        }

        if ($type === 'welcome-screen') {
            if ($connection) {
                try {
                    $welcomeSettings = $currentSettings['welcome-screen'] ?? [];
                    $InstagramService->setWelcomeScreenSettings($connection, $welcomeSettings);
                } catch (\Exception $e) {
                    return new JsonResponse([
                        'success' => false,
                        'error' => 'Saved locally but failed to update Instagram: ' . $e->getMessage()
                    ], 500);
                }
            }
        }

        $connection->setBotSettings($currentSettings);
        $em->flush();

        return new JsonResponse([
            'success' => true,
            'message' => 'Settings saved successfully.',
            'data'    => $currentSettings[$type]
        ]);
    }

    #[Route('/Instagram-bot-manager/sync-persistent-menu', name: 'app_instagram_bot_sync_persistent_menu', methods: ['POST'])]
    public function syncPersistentMenu(
        Request $request,
        EntityManagerInterface $em,
        InstagramService $InstagramService
    ): JsonResponse {
        $connectionId = (int)$request->request->get('connectionId');
        if (!$connectionId) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid Instagram Connection ID.'], 400);
        }

        $connection = $em->getRepository(InstagramConnection::class)->find($connectionId);
        if (!$connection) {
            return new JsonResponse(['success' => false, 'error' => 'Instagram connection not found.'], 404);
        }

        try {
            $menuItems = $InstagramService->syncPersistentMenuFromInstagram($connection);
            return new JsonResponse([
                'success' => true,
                'message' => 'Persistent menu synced from Instagram successfully.',
                'data' => $menuItems
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Failed to sync from Instagram: ' . $e->getMessage()
            ], 500);
        }
    }

    #[Route('/Instagram-bot-manager/sync-welcome-screen', name: 'app_instagram_bot_sync_welcome_screen', methods: ['POST'])]
    public function syncWelcomeScreen(
        Request $request,
        EntityManagerInterface $em,
        InstagramService $InstagramService
    ): JsonResponse {
        $connectionId = (int)$request->request->get('connectionId');
        if (!$connectionId) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid Instagram Connection ID.'], 400);
        }

        $connection = $em->getRepository(InstagramConnection::class)->find($connectionId);
        if (!$connection) {
            return new JsonResponse(['success' => false, 'error' => 'Instagram connection not found.'], 404);
        }

        try {
            $welcomeSettings = $InstagramService->syncWelcomeScreenFromInstagram($connection);
            return new JsonResponse([
                'success' => true,
                'message' => 'Welcome screen settings synced from Instagram successfully.',
                'data' => $welcomeSettings
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Failed to sync from Instagram: ' . $e->getMessage()
            ], 500);
        }
    }

    #[Route('/Instagram-bot-manager/get-connection-details', name: 'app_instagram_bot_connection_details', methods: ['GET'])]
    public function getConnectionDetails(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $connectionId = (int)$request->query->get('connectionId');
        $connection = $em->getRepository(InstagramConnection::class)->find($connectionId);
        if (!$connection) {
            return new JsonResponse(['success' => false, 'error' => 'Connection not found.'], 404);
        }
        
        $flows = $em->getRepository(InstagramBotFlow::class)->findBy(['InstagramConnection' => $connection], ['id' => 'DESC']);
        $flowsArray = [];
        foreach ($flows as $flow) {
            $flowsArray[] = [
                'id' => $flow->getId(),
                'name' => $flow->getName()
            ];
        }

        // Fetch Full Page Comment Automation settings from Database
        $automationRepo = $em->getRepository(\App\Entity\InstagramCommentAutomation::class);
        $automation = $automationRepo->findOneBy([
            'InstagramConnection' => $connection,
            'postId' => null
        ]);
        
        $savedSettings = $automation ? $automation->getSettingsArray() : [];
        
        $defaultCommentSettings = [
            'hideOrDelete' => 'hide',
            'offensiveKeywords' => '',
            'offensivePrivateReplyFlowId' => '',
            'sendReplyMultipleTimes' => false,
            'enableCommentReply' => true,
            'hideCommentAfterReply' => false,
            'automationMode' => 'generic',
            'campaignName' => '',
            'aiContextId' => '',
            'privateReplyFlowId' => '',
            'commentReplyText' => '',
            'filterMatchType' => 'exact',
            'filterWords' => ''
        ];
        
        $commentSettings = array_replace($defaultCommentSettings, $savedSettings ?? []);

        return new JsonResponse([
            'success' => true,
            'connection' => [
                'id' => $connection->getId(),
                'pageName' => $connection->getPageName(),
                'pageId' => $connection->getPageId()
            ],
            'flows' => $flowsArray,
            'commentSettings' => $commentSettings
        ]);
    }

    #[Route('/Instagram-bot-manager/flows', name: 'app_instagram_bot_flows', methods: ['GET'])]
    public function flows(Request $request, EntityManagerInterface $em): Response
    {
        $connections = $em->getRepository(InstagramConnection::class)->findBy([], ['id' => 'DESC']);
        
        $selectedConnectionId = $request->query->get('connectionId');
        $selectedConnection = null;
        if ($selectedConnectionId) {
            $selectedConnection = $em->getRepository(InstagramConnection::class)->find($selectedConnectionId);
        }
        if (!$selectedConnection && !empty($connections)) {
            $selectedConnection = $connections[0];
        }

        $flows = [];
        $defaultTemplates = [
            ['name' => 'welcome_offer_mm', 'language' => 'en_US'],
            ['name' => 'abandoned_cart_reminder', 'language' => 'en_US'],
        ];
        $templates = $defaultTemplates;

        if ($selectedConnection) {
            $flows = $em->getRepository(InstagramBotFlow::class)->findBy(['InstagramConnection' => $selectedConnection], ['id' => 'DESC']);
            
            $saved = $selectedConnection->getBotSettings() ?: [];
            if (isset($saved['broadcast-template']) && \is_array($saved['broadcast-template'])) {
                $templates = $saved['broadcast-template'];
            }
        }

        $payload = array_map(static fn (InstagramBotFlow $f) => self::flowToArray($f), $flows);

        $httpApis = $em->getRepository(\App\Entity\HttpApi::class)->findBy(['status' => 'active'], ['name' => 'ASC']);

        return $this->render('Instagram_bot_manager/flows.html.twig', [
            'flows'       => $flows,
            'flowsJson'   => $payload,
            'connection'  => $selectedConnection,
            'connections' => $connections,
            'templates'   => $templates,
            'httpApis'    => $httpApis,
        ]);
    }

    #[Route('/Instagram-bot-manager/sequence-builder', name: 'app_instagram_bot_sequence_builder', methods: ['GET'])]
    public function sequenceBuilder(Request $request, EntityManagerInterface $em): Response
    {
        $connectionId = (int)$request->query->get('connectionId');
        $connection = $em->getRepository(InstagramConnection::class)->find($connectionId);
        if (!$connection) {
            throw $this->createNotFoundException('Connection not found.');
        }

        $sequenceId = $request->query->get('id');
        $sequence = null;

        if ($sequenceId) {
            $entity = $em->getRepository(InstagramDripSequence::class)->find((int)$sequenceId);
            if ($entity && $entity->getInstagramConnection()?->getId() === $connection->getId()) {
                $sequence = $entity->toArray();
            }
        }

        if (!$sequence) {
            // Default for a brand-new sequence (not yet persisted)
            $sequence = [
                'id' => null,
                'name' => 'New Sequence Campaign',
                'preferredTime' => 'anytime',
                'timezone' => 'UTC',
                'messageTag' => 'NON_PROMOTIONAL_SUBSCRIPTION',
                'allowReentry' => false,
                'isActive' => true,
                'stepsCount' => 0,
                'graph' => null
            ];
        }

        $flows = $em->getRepository(InstagramBotFlow::class)->findBy(['InstagramConnection' => $connection], ['id' => 'DESC']);
        $contexts = $em->getRepository(AiContext::class)->findBy([], ['id' => 'DESC']);
        $httpApis = $em->getRepository(\App\Entity\HttpApi::class)->findBy(['status' => 'active'], ['name' => 'ASC']);

        return $this->render('Instagram_bot_manager/sequence_builder.html.twig', [
            'connection' => $connection,
            'sequence' => $sequence,
            'flows' => $flows,
            'contexts' => $contexts,
            'httpApis' => $httpApis,
        ]);
    }

    #[Route('/Instagram-bot-manager/sequence-builder/save', name: 'app_instagram_bot_sequence_save', methods: ['POST'])]
    public function saveSequence(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid payload'], 400);
        }

        $connectionId = (int)($payload['connectionId'] ?? 0);
        $connection = $em->getRepository(InstagramConnection::class)->find($connectionId);
        if (!$connection) {
            return new JsonResponse(['success' => false, 'error' => 'Connection not found.'], 404);
        }

        $sequenceId = $payload['id'] ?? null;
        $entity = null;

        if ($sequenceId) {
            $entity = $em->getRepository(InstagramDripSequence::class)->find((int)$sequenceId);
            // Verify it belongs to the same connection
            if ($entity && $entity->getInstagramConnection()?->getId() !== $connection->getId()) {
                $entity = null;
            }
        }

        if (!$entity) {
            $entity = new InstagramDripSequence();
            $entity->setInstagramConnection($connection);
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

    #[Route('/Instagram-bot-manager/sequence-builder/delete', name: 'app_instagram_bot_sequence_delete', methods: ['POST'])]
    public function deleteSequence(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid payload'], 400);
        }

        $sequenceId = (int)($payload['sequenceId'] ?? 0);
        $entity = $em->getRepository(InstagramDripSequence::class)->find($sequenceId);
        if (!$entity) {
            return new JsonResponse(['success' => false, 'error' => 'Sequence not found.'], 404);
        }

        $em->remove($entity);
        $em->flush();

        return new JsonResponse(['success' => true]);
    }

    #[Route('/Instagram-bot-manager/flows/save', name: 'app_instagram_bot_flows_save', methods: ['POST'])]
    public function saveFlow(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid payload'], 400);
        }

        $id        = isset($payload['id']) ? (int)$payload['id'] : null;
        $name      = trim((string)($payload['name'] ?? ''));
        $keywords  = $this->normaliseKeywords($payload['keywords'] ?? '');
        $matchMode = (string)($payload['matchMode'] ?? InstagramBotFlow::MATCH_EXACT);
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

        $flow = $id ? $em->getRepository(InstagramBotFlow::class)->find($id) : null;
        if (!$flow) {
            $flow = new InstagramBotFlow();
        }

        // Get selected connection
        $connectionId = isset($payload['connectionId']) ? (int)$payload['connectionId'] : (int)$request->query->get('connectionId');
        $connection = null;
        if ($connectionId) {
            $connection = $em->getRepository(InstagramConnection::class)->find($connectionId);
        }
        if (!$connection) {
            // Default to the first connection
            $connections = $em->getRepository(InstagramConnection::class)->findBy([], ['id' => 'DESC']);
            $connection = $connections[0] ?? null;
        }

        $flow->setInstagramConnection($connection);
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

    #[Route('/Instagram-bot-manager/flows/{id}/delete', name: 'app_instagram_bot_flows_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function deleteFlow(int $id, EntityManagerInterface $em): JsonResponse
    {
        $flow = $em->getRepository(InstagramBotFlow::class)->find($id);
        if (!$flow) {
            return new JsonResponse(['success' => false, 'error' => 'Flow not found.'], 404);
        }
        $em->remove($flow);
        $em->flush();

        return new JsonResponse(['success' => true]);
    }

    #[Route('/Instagram-bot-manager/flows/{id}/toggle', name: 'app_instagram_bot_flows_toggle', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function toggleFlow(int $id, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $flow = $em->getRepository(InstagramBotFlow::class)->find($id);
        if (!$flow) {
            return new JsonResponse(['success' => false, 'error' => 'Flow not found.'], 404);
        }

        $payload = json_decode($request->getContent(), true);
        $isActive = isset($payload['isActive']) ? (bool)$payload['isActive'] : !$flow->isActive();

        $flow->setActive($isActive);
        $em->flush();

        return new JsonResponse(['success' => true, 'isActive' => $flow->isActive()]);
    }

    #[Route('/Instagram-bot-manager/flows/{id}/clone', name: 'app_instagram_bot_flows_clone', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function cloneFlow(int $id, EntityManagerInterface $em): JsonResponse
    {
        $flow = $em->getRepository(InstagramBotFlow::class)->find($id);
        if (!$flow) {
            return new JsonResponse(['success' => false, 'error' => 'Flow not found.'], 404);
        }

        $clone = new InstagramBotFlow();
        $clone->setName($flow->getName() . ' (Copy)');
        $clone->setTriggerKeyword($flow->getTriggerKeyword());
        $clone->setMatchMode($flow->getMatchMode());
        $clone->setActive($flow->isActive());
        $clone->setFlowData($flow->getFlowData());
        $clone->setInstagramConnection($flow->getInstagramConnection());

        $em->persist($clone);
        $em->flush();

        return new JsonResponse(['success' => true]);
    }

    #[Route('/Instagram-bot-manager/flows/{id}/export', name: 'app_instagram_bot_flows_export', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function exportFlow(int $id, EntityManagerInterface $em): Response
    {
        $flow = $em->getRepository(InstagramBotFlow::class)->find($id);
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

    #[Route('/Instagram-bot-manager/ai-settings/agent', name: 'app_instagram_bot_ai_agent_save', methods: ['POST'])]
    public function saveAiAgentSettings(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $connectionId = $request->request->get('connectionId');
        $connection = null;
        if ($connectionId) {
            $connection = $em->getRepository(InstagramConnection::class)->find($connectionId);
        }
        if (!$connection) {
            $connection = $em->getRepository(InstagramConnection::class)->findOneBy([]);
        }

        if (!$connection) {
            return new JsonResponse(['success' => false, 'error' => 'No Instagram Connection found.']);
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
            'message' => 'Instagram AI Agent Settings saved successfully.',
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
    private static function flowToArray(InstagramBotFlow $flow): array
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

    // Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬ Social Posting Endpoints Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬

    #[Route('/Instagram-bot-manager/posts/list', name: 'app_instagram_bot_posts_list', methods: ['GET'])]
    public function listPosts(Request $request, EntityManagerInterface $em, InstagramService $InstagramService): JsonResponse
    {
        $connectionId = (int)$request->query->get('connectionId');
        if (!$connectionId) {
            return new JsonResponse(['success' => false, 'error' => 'Connection ID is required.'], 400);
        }

        $refresh = filter_var($request->query->get('refresh'), FILTER_VALIDATE_BOOLEAN);

        $connection = $em->getRepository(InstagramConnection::class)->find($connectionId);
        if (!$connection) {
            return new JsonResponse(['success' => false, 'error' => 'Connection not found.'], 404);
        }

        $cache = $connection->getPostsCache() ?: [];
        $posts = $cache['posts'] ?? [];
        $lastUpdated = $cache['updatedAt'] ?? 0;

        $needsRefresh = empty($posts) || (time() - $lastUpdated > 300);

        if ($refresh || $needsRefresh) {
            if ($connection) {
                try {
                    $feed = $InstagramService->fetchPageFeed($connection, 50);

                    // We map local posts by fbPostId for fast lookups
                    $localPostsByFbId = [];
                    $otherLocalPosts = [];
                    foreach ($posts as $p) {
                        if (!empty($p['fbPostId'])) {
                            $localPostsByFbId[$p['fbPostId']] = $p;
                        } else {
                            $otherLocalPosts[] = $p;
                        }
                    }

                    $syncedPosts = [];
                    // Process feed posts
                    foreach ($feed as $fbPost) {
                        $fbId = $fbPost['id'];
                        $likesCount = $fbPost['like_count'] ?? 0;
                        $commentsCount = $fbPost['comments_count'] ?? 0;
                        $sharesCount = 0; // Not exposed natively via IG media edge

                        if (isset($localPostsByFbId[$fbId])) {
                            // Update existing local post
                            $localPost = $localPostsByFbId[$fbId];
                            $localPost['status'] = 'published';
                            $localPost['errorMessage'] = null;
                            $localPost['likes'] = $likesCount;
                            $localPost['comments'] = $commentsCount;
                            $localPost['shares'] = $sharesCount;
                            if (isset($fbPost['permalink'])) {
                                $localPost['link'] = $fbPost['permalink'];
                            }
                            $syncedPosts[] = $localPost;
                            unset($localPostsByFbId[$fbId]);
                        } else {
                            // Try matching by message for unpublished posts that were actually published
                            $matchedIndex = -1;
                            foreach ($otherLocalPosts as $idx => $olp) {
                                if ($olp['status'] !== 'published' && !empty($olp['message']) && !empty($fbPost['caption']) && levenshtein($olp['message'], $fbPost['caption']) < 5) {
                                    $matchedIndex = $idx;
                                    break;
                                }
                            }

                            if ($matchedIndex !== -1) {
                                $localPost = $otherLocalPosts[$matchedIndex];
                                $localPost['fbPostId'] = $fbId;
                                $localPost['status'] = 'published';
                                $localPost['errorMessage'] = null;
                                $localPost['likes'] = $likesCount;
                                $localPost['comments'] = $commentsCount;
                                $localPost['shares'] = $sharesCount;
                                if (isset($fbPost['permalink'])) {
                                    $localPost['link'] = $fbPost['permalink'];
                                }
                                $syncedPosts[] = $localPost;
                                array_splice($otherLocalPosts, $matchedIndex, 1);
                            } else {
                                // New post from Instagram directly
                                $syncedPosts[] = [
                                    'id' => uniqid('post_'),
                                    'createdAt' => isset($fbPost['timestamp']) ? (new \DateTime($fbPost['timestamp']))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s') : date('Y-m-d H:i:s'),
                                    'type' => 'multimedia',
                                    'message' => $fbPost['caption'] ?? '',
                                    'link' => $fbPost['permalink'] ?? '',
                                    'mediaType' => isset($fbPost['media_type']) ? strtolower($fbPost['media_type']) : 'image',
                                    'mediaUrl' => $fbPost['media_url'] ?? ($fbPost['thumbnail_url'] ?? ''),
                                    'status' => 'published',
                                    'fbPostId' => $fbId,
                                    'likes' => $likesCount,
                                    'comments' => $commentsCount,
                                    'shares' => $sharesCount,
                                ];
                            }
                        }
                    }

                    // Put back any remaining local posts (drafts, failed, or published but outside current feed limit)
                    foreach ($localPostsByFbId as $lp) {
                        $syncedPosts[] = $lp;
                    }
                    foreach ($otherLocalPosts as $olp) {
                        $syncedPosts[] = $olp;
                    }

                    // Sort posts by createdAt DESC
                    usort($syncedPosts, function ($a, $b) {
                        return strcmp($b['createdAt'], $a['createdAt']);
                    });

                    $posts = $syncedPosts;
                    $connection->setPostsCache(['updatedAt' => time(), 'posts' => $posts]);
                    $em->flush();
                } catch (\Exception $e) {
                    return new JsonResponse([
                        'success' => true,
                        'posts' => $posts,
                        'syncError' => $e->getMessage()
                    ]);
                }
            }
        }

        // Attach DB-based comment automation settings to posts
        if ($connection) {
            $automationRepo = $em->getRepository(\App\Entity\InstagramCommentAutomation::class);
            // Fetch all automations for this connection that have a postId
            $automations = $automationRepo->createQueryBuilder('a')
                ->where('a.InstagramConnection = :conn')
                ->andWhere('a.postId IS NOT NULL')
                ->setParameter('conn', $connection)
                ->getQuery()
                ->getResult();
                
            $settingsMap = [];
            foreach ($automations as $automation) {
                $settingsMap[$automation->getPostId()] = $automation->getSettingsArray();
            }

            foreach ($posts as $idx => $post) {
                $pId = $post['id'] ?? null;
                $fbId = $post['fbPostId'] ?? null;
                
                if ($fbId && isset($settingsMap[$fbId])) {
                    $posts[$idx]['commentAutomationSettings'] = $settingsMap[$fbId];
                } elseif ($pId && isset($settingsMap[$pId])) {
                    $posts[$idx]['commentAutomationSettings'] = $settingsMap[$pId];
                } else {
                    $posts[$idx]['commentAutomationSettings'] = null;
                }
            }
        }

        return new JsonResponse(['success' => true, 'posts' => $posts]);
    }

    #[Route('/Instagram-bot-manager/posts/save', name: 'app_instagram_bot_posts_save', methods: ['POST'])]
    public function savePost(Request $request, EntityManagerInterface $em, InstagramService $InstagramService): JsonResponse
    {
        $connectionId = (int)$request->request->get('connectionId');
        if (!$connectionId) {
            return new JsonResponse(['success' => false, 'error' => 'Connection ID is required.'], 400);
        }

        $connection = $em->getRepository(InstagramConnection::class)->find($connectionId);
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

        $cache = $connection->getPostsCache() ?: [];
        $posts = $cache['posts'] ?? [];

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

        $scheduledTimeStr = $request->request->get('scheduledTime');
        $timezoneStr = $request->request->get('timezone', 'UTC');

        if ($publishNow) {
            $post['status'] = 'published';
            $post['scheduledAt'] = null;
        } elseif (!empty($scheduledTimeStr)) {
            try {
                $tz = new \DateTimeZone($timezoneStr);
                $dt = new \DateTime($scheduledTimeStr, $tz);
                $dt->setTimezone(new \DateTimeZone('UTC'));
                $post['scheduledAt'] = $dt->format('Y-m-d H:i:s');
                $post['status'] = 'scheduled';
            } catch (\Exception $e) {
                $post['status'] = 'draft';
                $post['scheduledAt'] = null;
            }
        } else {
            $post['status'] = 'draft';
            $post['scheduledAt'] = null;
        }

        if ($publishNow) {
            try {
                $result = null;
                if ($type === 'multimedia') {
                    if ($mediaType === 'image' && $mediaUrl !== '') {
                        $result = $InstagramService->publishPhotoPost($connection, $mediaUrl, $message);
                    } elseif ($mediaType === 'video' && $mediaUrl !== '') {
                        $result = $InstagramService->publishVideoPost($connection, $mediaUrl, $message);
                    } else {
                        $result = $InstagramService->publishFeedPost($connection, $message, $link);
                    }
                } elseif ($type === 'cta') {
                    $result = $InstagramService->publishCtaPost($connection, $message, $link, $ctaType);
                } elseif ($type === 'carousel') {
                    $result = $InstagramService->publishCarouselPost($connection, $message, $slides);
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

        $cache['posts'] = $posts;
        $cache['updatedAt'] = time();
        $connection->setPostsCache($cache);
        $em->flush();

        return new JsonResponse(['success' => true, 'post' => $post]);
    }

    #[Route('/Instagram-bot-manager/posts/publish/{id}', name: 'app_instagram_bot_posts_publish', methods: ['POST'])]
    public function publishPost(string $id, Request $request, EntityManagerInterface $em, InstagramService $InstagramService): JsonResponse
    {
        $connectionId = (int)$request->request->get('connectionId');
        if (!$connectionId) {
            return new JsonResponse(['success' => false, 'error' => 'Connection ID is required.'], 400);
        }

        $connection = $em->getRepository(InstagramConnection::class)->find($connectionId);
        if (!$connection) {
            return new JsonResponse(['success' => false, 'error' => 'Connection not found.'], 404);
        }

        $cache = $connection->getPostsCache() ?: [];
        $posts = $cache['posts'] ?? [];
        if (empty($posts)) {
            return new JsonResponse(['success' => false, 'error' => 'Post log not found.'], 404);
        }
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
                    $result = $InstagramService->publishPhotoPost($connection, $mediaUrl, $message);
                } elseif ($mediaType === 'video' && $mediaUrl !== '') {
                    $result = $InstagramService->publishVideoPost($connection, $mediaUrl, $message);
                } else {
                    $result = $InstagramService->publishFeedPost($connection, $message, $link);
                }
            } elseif ($type === 'cta') {
                $result = $InstagramService->publishCtaPost($connection, $message, $link, $ctaType);
            } elseif ($type === 'carousel') {
                $result = $InstagramService->publishCarouselPost($connection, $message, $slides);
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
        $cache['posts'] = $posts;
        $cache['updatedAt'] = time();
        $connection->setPostsCache($cache);
        $em->flush();

        return new JsonResponse(['success' => true, 'post' => $post]);
    }

    #[Route('/Instagram-bot-manager/posts/delete/{id}', name: 'app_instagram_bot_posts_delete', methods: ['POST'])]
    public function deletePost(string $id, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $connectionId = (int)$request->request->get('connectionId');
        if (!$connectionId) {
            return new JsonResponse(['success' => false, 'error' => 'Connection ID is required.'], 400);
        }

        $connection = $em->getRepository(InstagramConnection::class)->find($connectionId);
        if (!$connection) {
            return new JsonResponse(['success' => false, 'error' => 'Connection not found.'], 404);
        }

        $cache = $connection->getPostsCache() ?: [];
        $posts = $cache['posts'] ?? [];
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

        $cache['posts'] = $updatedPosts;
        $cache['updatedAt'] = time();
        $connection->setPostsCache($cache);
        $em->flush();

        return new JsonResponse(['success' => true]);
    }

    #[Route('/Instagram-bot-manager/posts/save-comment-settings', name: 'app_instagram_bot_posts_save_comment_settings', methods: ['POST'])]
    public function savePostCommentSettings(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $connectionId = (int)$request->request->get('connectionId');
        $postId = $request->request->get('postId');
        $settingsRaw = $request->request->get('settings');

        if (!$connectionId || !$postId) {
            return new JsonResponse(['success' => false, 'error' => 'Connection ID and Post ID are required.'], 400);
        }

        $connection = $em->getRepository(InstagramConnection::class)->find($connectionId);
        if (!$connection) {
            return new JsonResponse(['success' => false, 'error' => 'Connection not found.'], 404);
        }

        $settings = null;
        if (\is_string($settingsRaw)) {
            $settings = json_decode($settingsRaw, true);
        } elseif (\is_array($settingsRaw)) {
            $settings = $settingsRaw;
        }

        $automationRepo = $em->getRepository(\App\Entity\InstagramCommentAutomation::class);
        $automation = $automationRepo->findOneBy([
            'InstagramConnection' => $connection,
            'postId' => $postId
        ]);

        if (!$automation) {
            $automation = new \App\Entity\InstagramCommentAutomation();
            $automation->setInstagramConnection($connection);
            $automation->setPostId($postId);
            $automation->setOwner($connection->getOwner());
        }

        $automation->populateFromSettingsArray(is_array($settings) ? $settings : []);
        $em->persist($automation);
        $em->flush();

        return new JsonResponse(['success' => true]);
    }

    #[Route('/Instagram-bot-manager/posts/comment-manual', name: 'app_instagram_bot_posts_comment_manual', methods: ['POST'])]
    public function manualComment(Request $request, EntityManagerInterface $em, InstagramService $instagramService): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $postId = $data['postId'] ?? null;
            $message = $data['message'] ?? null;
            $connectionId = $data['connectionId'] ?? null;

            if (!$postId || !$message || !$connectionId) {
                return new JsonResponse(['success' => false, 'error' => 'Missing required fields (postId, message, connectionId).'], 400);
            }

            $connection = $em->getRepository(InstagramConnection::class)->find($connectionId);
            if (!$connection) {
                return new JsonResponse(['success' => false, 'error' => 'Connection not found.'], 404);
            }

            $result = $instagramService->commentOnPost($postId, $message, null, $connection);

            return new JsonResponse([
                'success' => true,
                'result' => $result
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    #[Route('/Instagram-bot-manager/posts/add-by-id', name: 'app_instagram_bot_posts_add_by_id', methods: ['POST'])]
    public function addPostById(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $connectionId = (int)$request->request->get('connectionId');
        $fbPostId = trim((string)$request->request->get('fbPostId'));

        if (!$connectionId || empty($fbPostId)) {
            return new JsonResponse(['success' => false, 'error' => 'Connection ID and Instagram Post ID are required.'], 400);
        }

        $connection = $em->getRepository(InstagramConnection::class)->find($connectionId);
        if (!$connection) {
            return new JsonResponse(['success' => false, 'error' => 'Connection not found.'], 404);
        }

        $cache = $connection->getPostsCache() ?: [];
        $posts = $cache['posts'] ?? [];

        $existingPost = null;
        foreach ($posts as $p) {
            if (($p['fbPostId'] ?? '') === $fbPostId) {
                $existingPost = $p;
                break;
            }
        }

        if ($existingPost) {
            return new JsonResponse(['success' => true, 'post' => $existingPost]);
        }

        $newPost = [
            'id' => uniqid('post_'),
            'createdAt' => (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
            'type' => 'multimedia',
            'message' => 'Set Campaign by ID: ' . $fbPostId,
            'link' => '',
            'mediaType' => 'none',
            'mediaUrl' => '',
            'status' => 'published',
            'fbPostId' => $fbPostId,
            'likes' => 0,
            'comments' => 0,
            'shares' => 0,
            'commentAutomationSettings' => null
        ];

        array_unshift($posts, $newPost);
        $cache['posts'] = $posts;
        $cache['updatedAt'] = time();
        $connection->setPostsCache($cache);
        $em->flush();

        return new JsonResponse(['success' => true, 'post' => $newPost]);
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

