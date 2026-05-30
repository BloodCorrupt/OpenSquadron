<?php

namespace App\Controller;

use App\Entity\FacebookConnection;
use App\Entity\Message;
use App\Entity\Subscriber;
use App\Service\FacebookService;
use App\Service\FacebookBotFlowExecutor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class FacebookWebhookController extends AbstractController
{
    public function __construct(
        private FacebookService $facebookService,
        private EntityManagerInterface $entityManager,
        private FacebookBotFlowExecutor $WhatsappBotFlowExecutor,
        private \App\Service\AiAgentService $aiAgentService,
        private \App\Service\TenantContext $tenantContext,
        private HttpClientInterface $httpClient
    ) {
    }

    #[Route('/webhook/facebook', name: 'facebook_webhook_verify', methods: ['GET'])]
    public function verifyWebhook(Request $request): Response
    {
        $mode = $request->query->get('hub_mode', $request->query->get('hub.mode'));
        $token = $request->query->get('hub_verify_token', $request->query->get('hub.verify_token'));
        $challenge = $request->query->get('hub_challenge', $request->query->get('hub.challenge'));

        if ($token) {
            $this->tenantContext->disableTenantFilter();
            $connection = $this->entityManager->getRepository(FacebookConnection::class)->findOneBy(['verifyToken' => $token]);
            if ($connection) {
                $owner = $connection->getOwner();
                if ($owner) {
                    $this->tenantContext->setCurrentOwner($owner);
                }
            } else {
                $setting = $this->entityManager->getRepository(\App\Entity\MetaSetting::class)->findOneBy(['verifyToken' => $token]);
                if ($setting) {
                    $owner = $setting->getOwner();
                    if ($owner) {
                        $this->tenantContext->setCurrentOwner($owner);
                    }
                }
            }
        }

        if ($mode && $token) {
            // Find a connection or setting with this verify token
            $this->tenantContext->disableTenantFilter();
            $connection = $this->entityManager->getRepository(FacebookConnection::class)->findOneBy(['verifyToken' => $token]);
            $setting = null;
            if (!$connection) {
                $setting = $this->entityManager->getRepository(\App\Entity\MetaSetting::class)->findOneBy(['verifyToken' => $token]);
            }

            if ($mode === 'subscribe' && ($connection || $setting)) {
                return new Response($challenge, 200);
            }
            return new Response('Forbidden', 403);
        }

        return new Response('Bad Request', 400);
    }

    #[Route('/webhook/facebook', name: 'facebook_webhook_handle', methods: ['POST'])]
    public function handleWebhook(Request $request, \App\Controller\InstagramWebhookController $igWebhookController = null): JsonResponse
    {
        $payload = $request->getContent();
        // Log incoming payload for debugging
        file_put_contents($this->getParameter('kernel.project_dir') . '/var/facebook_webhook.log', date('Y-m-d H:i:s') . " - " . $payload . PHP_EOL, FILE_APPEND);

        $content = json_decode($payload, true);

        if (!$content) {
            return new JsonResponse(['status' => 'invalid payload'], 400);
        }

        // If this is not the internal async request, we queue it and return 200 OK instantly.
        if ($request->headers->get('X-Internal-Async') !== 'true') {
            $ch = curl_init('http://127.0.0.1' . $request->getRequestUri());
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'X-Internal-Async: true',
                'Host: ' . $request->getHost()
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT_MS, 100);
            curl_exec($ch);
            curl_close($ch);

            return new JsonResponse(['status' => 'success'], 200);
        }

        // We are now inside the internal async request, allow it to run in the background
        ignore_user_abort(true);
        set_time_limit(0);

        // Facebook Messenger sends the data with object = 'page'
        if (isset($content['object']) && $content['object'] === 'page') {
            $this->tenantContext->disableTenantFilter();

            foreach ($content['entry'] as $entry) {
                $pageId = $entry['id'] ?? null;

                // Resolve the Facebook connection by Page ID
                $resolvedConnection = null;
                if ($pageId) {
                    $resolvedConnection = $this->entityManager
                        ->getRepository(FacebookConnection::class)
                        ->findOneBy(['pageId' => $pageId]);
                }

                if ($resolvedConnection) {
                    if ($resolvedConnection->getStatus() !== 'active') {
                        continue;
                    }

                    $owner = $resolvedConnection->getOwner();
                    if ($owner) {
                        $this->tenantContext->setCurrentOwner($owner);
                    }
                }

                // Process feed webhook changes (comment automation)
                if (isset($entry['changes']) && is_array($entry['changes']) && $resolvedConnection) {
                    foreach ($entry['changes'] as $change) {
                        if (($change['field'] ?? '') === 'feed') {
                            $value = $change['value'] ?? [];
                            if (($value['item'] ?? '') === 'comment' && ($value['verb'] ?? '') === 'add') {
                                try {
                                    $this->handleCommentAddition($value, $resolvedConnection);
                                } catch (\Throwable $e) {
                                    file_put_contents($this->getParameter('kernel.project_dir') . '/var/facebook_webhook.log', date('Y-m-d H:i:s') . " - Error handling comment: " . $e->getMessage() . PHP_EOL . $e->getTraceAsString() . PHP_EOL, FILE_APPEND);
                                }
                            }
                        }
                    }
                }

                if (!isset($entry['messaging'])) {
                    continue;
                }

                foreach ($entry['messaging'] as $messagingEvent) {
                    // Only process message, postback, or optin events (not deliveries, reads, etc.)
                    if (!isset($messagingEvent['message']) && !isset($messagingEvent['postback']) && !isset($messagingEvent['optin'])) {
                        continue;
                    }

                    $senderPsid = $messagingEvent['sender']['id'] ?? null;
                    if (!$senderPsid || $senderPsid === $pageId) {
                        // Skip messages sent by the page itself (echo)
                        continue;
                    }

                    // Check for echo flag
                    if (isset($messagingEvent['message']['is_echo']) && $messagingEvent['message']['is_echo']) {
                        continue;
                    }

                    $msgBody = '';
                    $msgType = 'text';
                    $mediaUrl = null;

                    if (isset($messagingEvent['message']['text'])) {
                        $msgBody = $messagingEvent['message']['text'];
                    } elseif (isset($messagingEvent['postback']['payload'])) {
                        $msgBody = $messagingEvent['postback']['payload'];
                    } elseif (isset($messagingEvent['optin']['payload'])) {
                        $msgBody = $messagingEvent['optin']['payload'];
                        $optinToken = $messagingEvent['optin']['notification_messages_token'] ?? null;
                        if ($optinToken) {
                            // Log the token capture for Marketing Messages
                            file_put_contents($this->getParameter('kernel.project_dir') . '/var/facebook_webhook.log', date('Y-m-d H:i:s') . " - Captured Marketing Message Token: " . $optinToken . " for payload " . $msgBody . PHP_EOL, FILE_APPEND);
                            // TODO: Persist the token to the database or settings for future broadcasting.
                        }
                    }

                    // Handle attachments (images, audio, files)
                    if (isset($messagingEvent['message']['attachments'])) {
                        $attachment = $messagingEvent['message']['attachments'][0] ?? null;
                        if ($attachment) {
                            $attachmentType = $attachment['type'] ?? 'fallback';
                            $attachmentUrl = $attachment['payload']['url'] ?? null;

                            if (in_array($attachmentType, ['image', 'audio', 'video', 'file'])) {
                                $msgType = $attachmentType;
                                $mediaUrl = $attachmentUrl;
                            }
                        }
                    }

                    $metaMessageId = $messagingEvent['message']['mid'] ?? $messagingEvent['postback']['mid'] ?? ('pb_' . uniqid());

                    // Prevent processing the exact same webhook message event twice (e.g. if Meta retries)
                    if (!str_starts_with($metaMessageId, 'pb_')) {
                        $existingMsg = $this->entityManager->getRepository(Message::class)->findOneBy(['metaMessageId' => $metaMessageId]);
                        if ($existingMsg) {
                            continue; // Skip processing this duplicate webhook event
                        }
                    }

                    if ($msgBody !== '' || $mediaUrl !== null) {
                        // Find or create subscriber
                        $subscriber = $this->entityManager->getRepository(Subscriber::class)->findOneBy([
                            'psid' => $senderPsid,
                            'facebookConnection' => $resolvedConnection,
                        ]);

                        if (!$subscriber) {
                            $subscriber = new Subscriber();
                            $subscriber->setChannel('facebook');
                            $subscriber->setPsid($senderPsid);
                            $subscriber->setFacebookConnection($resolvedConnection);
                            // Try to fetch the user's name from Facebook profile
                            $profileName = $this->fetchUserProfile($senderPsid, $resolvedConnection);
                            if ($profileName) {
                                $subscriber->setName($profileName);
                            }
                            $this->entityManager->persist($subscriber);
                            } elseif (!$subscriber->getName() || $subscriber->getName() === $senderPsid) {
                            // Subscriber exists but has no name — retry profile lookup
                            $profileName = $this->fetchUserProfile($senderPsid, $resolvedConnection);
                            if ($profileName) {
                                $subscriber->setName($profileName);
                            }
                        }

                        $msg = new Message();
                        $msg->setSubscriber($subscriber);
                        $msg->setDirection('inbound');
                        $msg->setType($msgType);
                        $msg->setContent($msgBody);
                        $msg->setMediaUrl($mediaUrl);
                        $msg->setMetaMessageId($metaMessageId);
                        $msg->setStatus('received');

                        $this->entityManager->persist($msg);
                        // Check for Automations (Facebook Bot Flows) or waiting state interception.
                        $isResumed = false;
                        
                        // Check for opt-out/opt-in first (Unsubscribe / Resubscribe action buttons)
                        $msgBodyLower = strtolower(trim($msgBody));
                        $stopWords = ['stop', 'unsubscribe', 'cancel', 'quit', 'optout', 'opt-out'];
                        $startWords = ['start', 'subscribe', 'unstop', 'optin', 'opt-in'];

                        if (in_array($msgBodyLower, $stopWords)) {
                            $subscriber->setStatus('unsubscribed');
                            $this->entityManager->persist($subscriber);
                            $this->entityManager->flush();

                            $actionButton = $this->entityManager->getRepository(\App\Entity\FacebookActionButton::class)
                                ->findOneBy([
                                    'facebookConnection' => $resolvedConnection,
                                    'buttonKey' => 'unsubscribe',
                                    'isEnabled' => true
                                ]);
                            if ($actionButton) {
                                $this->executeFacebookActionButton($actionButton, $subscriber);
                            }
                            $isResumed = true;
                        } elseif (in_array($msgBodyLower, $startWords)) {
                            $subscriber->setStatus('active');
                            $this->entityManager->persist($subscriber);
                            $this->entityManager->flush();

                            $actionButton = $this->entityManager->getRepository(\App\Entity\FacebookActionButton::class)
                                ->findOneBy([
                                    'facebookConnection' => $resolvedConnection,
                                    'buttonKey' => 'resubscribe',
                                    'isEnabled' => true
                                ]);
                            if ($actionButton) {
                                $this->executeFacebookActionButton($actionButton, $subscriber);
                            }
                            $isResumed = true;
                        }

                        // If user is unsubscribed, ignore everything else
                        if (!$isResumed && $subscriber->getStatus() === 'unsubscribed') {
                            $isResumed = true; 
                        }

                        if (!$isResumed && $msgType === 'text' && $msgBody !== '') {
                            $attrs = $subscriber->getCustomAttributes();
                            $waitingForInput = $attrs['_waiting_for_input'] ?? null;
                            $waitingNodeId = $attrs['_waiting_node_id'] ?? null;
                            $waitingFlowId = $attrs['_waiting_flow_id'] ?? null;
                            $waitingFlowType = $attrs['_waiting_flow_type'] ?? null;

                            if (!empty($waitingForInput) && $waitingFlowType === 'facebook' && !empty($waitingFlowId)) {
                                // Save user response text in custom attributes under custom field key
                                $attrs[$waitingForInput] = $msgBody;
                                
                                // Clear waiting state keys
                                unset($attrs['_waiting_for_input'], $attrs['_waiting_node_id'], $attrs['_waiting_flow_id'], $attrs['_waiting_flow_type']);
                                $subscriber->setCustomAttributes($attrs);
                                $this->entityManager->flush();

                                // Find flow
                                $flow = $this->entityManager->getRepository(\App\Entity\FacebookBotFlow::class)->find($waitingFlowId);
                                if ($flow && $flow->isActive()) {
                                    $nextNodeId = null;
                                    $flowData = $flow->getFlowData();
                                    if (isset($flowData['format']) && $flowData['format'] === 'graph' && isset($flowData['edges'])) {
                                        foreach ($flowData['edges'] as $edge) {
                                            if (($edge['source'] ?? null) === $waitingNodeId && ($edge['sourceHandle'] ?? 'out') === 'out') {
                                                $nextNodeId = $edge['target'] ?? null;
                                                break;
                                            }
                                        }
                                    }

                                    if ($nextNodeId) {
                                        // Save the assigned flow
                                        $subscriber->setAssignedFacebookFlow($flow);
                                        $this->entityManager->persist($subscriber);

                                        // Resume the flow
                                        $this->WhatsappBotFlowExecutor->execute($flow, $subscriber, $nextNodeId);
                                    }
                                }
                                $isResumed = true;
                            }
                        }

                        // Check system events (Get Started, Human Escalation, Resume Bot)
                        if (!$isResumed && $msgType === 'text' && $msgBody !== '') {
                            // Check for get-started
                            if ($msgBody === 'WELCOME_GET_STARTED_TRIGGER') {
                                $actionButton = $this->entityManager->getRepository(\App\Entity\FacebookActionButton::class)
                                    ->findOneBy([
                                        'facebookConnection' => $resolvedConnection,
                                        'buttonKey' => 'get-started',
                                        'isEnabled' => true
                                    ]);
                                if ($actionButton) {
                                    $this->executeFacebookActionButton($actionButton, $subscriber);
                                    $isResumed = true;
                                }
                            }
                            // Check for Chat with Human
                            elseif ($msgBody === 'ESCALATE_HUMAN_TRIGGER') {
                                $subscriber->setStatus('paused');
                                $this->entityManager->persist($subscriber);
                                $this->entityManager->flush();

                                $actionButton = $this->entityManager->getRepository(\App\Entity\FacebookActionButton::class)
                                    ->findOneBy([
                                        'facebookConnection' => $resolvedConnection,
                                        'buttonKey' => 'chat-with-human',
                                        'isEnabled' => true
                                    ]);
                                if ($actionButton) {
                                    $this->executeFacebookActionButton($actionButton, $subscriber);
                                    $isResumed = true;
                                }
                            }
                            // Check for Chat with Bot (Resume)
                            elseif ($msgBody === 'RESUME_BOT_TRIGGER') {
                                $subscriber->setStatus('active');
                                $this->entityManager->persist($subscriber);
                                $this->entityManager->flush();

                                $actionButton = $this->entityManager->getRepository(\App\Entity\FacebookActionButton::class)
                                    ->findOneBy([
                                        'facebookConnection' => $resolvedConnection,
                                        'buttonKey' => 'chat-with-bot',
                                        'isEnabled' => true
                                    ]);
                                if ($actionButton) {
                                    $this->executeFacebookActionButton($actionButton, $subscriber);
                                    $isResumed = true;
                                }
                            }
                        }

                        // Check location reply
                        if (!$isResumed && $msgType === 'location') {
                            $actionButton = $this->entityManager->getRepository(\App\Entity\FacebookActionButton::class)
                                ->findOneBy([
                                    'facebookConnection' => $resolvedConnection,
                                    'buttonKey' => 'location-reply',
                                    'isEnabled' => true
                                ]);
                            if ($actionButton) {
                                $this->executeFacebookActionButton($actionButton, $subscriber);
                                $isResumed = true;
                            }
                        }

                        // Check Whitelist Mode dynamically
                        if (!$isResumed) {
                            $botSettings = $resolvedConnection->getBotSettings() ?? [];
                            $wlEnabled = $botSettings['whitelist-mode']['enabled'] ?? false;
                            if ($wlEnabled) {
                                $wlNumbersStr = $botSettings['whitelist-mode']['whitelistedNumbers'] ?? '';
                                $wlNumbers = array_filter(array_map('trim', preg_split('/[\n,]+/', $wlNumbersStr)));
                                if (!in_array($senderPsid, $wlNumbers)) {
                                    $isResumed = true;
                                    if (!$subscriber->isBotPaused()) {
                                        $subscriber->setBotPaused(true);
                                        $this->entityManager->persist($subscriber);
                                    }
                                }
                            }
                        }

                        // Check Blacklist Mode dynamically
                        if (!$isResumed) {
                            $botSettings = $resolvedConnection->getBotSettings() ?? [];
                            $blEnabled = $botSettings['blacklist-mode']['enabled'] ?? false;
                            if ($blEnabled) {
                                $blNumbersStr = $botSettings['blacklist-mode']['blacklistedNumbers'] ?? '';
                                $blNumbers = array_filter(array_map('trim', preg_split('/[\n,]+/', $blNumbersStr)));
                                if (in_array($senderPsid, $blNumbers)) {
                                    $isResumed = true;
                                    if (!$subscriber->isBotPaused()) {
                                        $subscriber->setBotPaused(true);
                                        $this->entityManager->persist($subscriber);
                                    }
                                }
                            }
                        }

                        // Check Business Hours
                        if (!$isResumed) {
                            $botSettings = $resolvedConnection->getBotSettings() ?? [];
                            $bh = $botSettings['business-hours'] ?? [];
                            if (!empty($bh['enabled'])) {
                                try {
                                    $tzString = !empty($bh['timezone']) ? trim((string)$bh['timezone']) : '';
                                    if ($tzString === '') {
                                        $tzString = ($resolvedConnection->getOwner() && $resolvedConnection->getOwner()->getTimezone()) ? $resolvedConnection->getOwner()->getTimezone() : 'UTC';
                                    }
                                    $tz = new \DateTimeZone($tzString);
                                    $now = new \DateTime('now', $tz);
                                    $currentDay = $now->format('l');
                                    $currentTime = $now->format('H:i');
                                    $activeDays = $bh['days'] ?? [];
                                    
                                    $isOutsideBH = (!in_array($currentDay, $activeDays) || $currentTime < ($bh['startTime'] ?? '09:00') || $currentTime > ($bh['endTime'] ?? '17:00'));
                                    $mode = $bh['mode'] ?? 'bot';
                                    
                                    if ($mode === 'bot' && $isOutsideBH) {
                                        $isResumed = true;
                                    } elseif ($mode === 'human' && !$isOutsideBH) {
                                        $isResumed = true;
                                    }
                                } catch (\Exception $e) {
                                    // Ignore exception and process normally if TZ is invalid
                                }
                            }
                        }

                        // If subscriber is paused (bot paused for live chat), ignore regular flows
                        if (!$isResumed && ($subscriber->getStatus() === 'paused' || $subscriber->isBotPaused())) {
                            $isResumed = true;
                        }

                        if ($isResumed) {
                            // Already handled
                        } elseif ($msgType === 'text' && $msgBody !== '' && $resolvedConnection) {
                            $flows = $this->entityManager
                                ->getRepository(\App\Entity\FacebookBotFlow::class)
                                ->findBy([
                                    'isActive' => true,
                                    'facebookConnection' => $resolvedConnection,
                                ]);

                            $matchedFlow = null;
                            if (str_starts_with($msgBody, 'FLOW_ID_')) {
                                $flowId = (int)substr($msgBody, 8);
                                $matchedFlow = $this->entityManager
                                    ->getRepository(\App\Entity\FacebookBotFlow::class)
                                    ->findOneBy([
                                        'id' => $flowId,
                                        'isActive' => true,
                                        'facebookConnection' => $resolvedConnection,
                                    ]);
                            } else {
                                foreach ($flows as $flow) {
                                    if ($flow->matches($msgBody)) {
                                        $matchedFlow = $flow;
                                        break;
                                    }
                                }
                            }

                            if ($matchedFlow) {
                                // Save the assigned flow
                                $subscriber->setAssignedFacebookFlow($matchedFlow);
                                $this->entityManager->persist($subscriber);

                                // Execute the flow
                                $this->WhatsappBotFlowExecutor->execute($matchedFlow, $subscriber);
                            } else {
                                // No keyword match! Check if No Match is enabled and trigger it.
                                $actionButton = $this->entityManager->getRepository(\App\Entity\FacebookActionButton::class)
                                    ->findOneBy([
                                        'facebookConnection' => $resolvedConnection,
                                        'buttonKey' => 'no-match',
                                        'isEnabled' => true
                                    ]);
                                if ($actionButton) {
                                    $this->executeFacebookActionButton($actionButton, $subscriber);
                                } elseif ($resolvedConnection && $resolvedConnection->isAiActive()) {
                                    $aiSetting = $this->entityManager->getRepository(\App\Entity\AiSetting::class)->findOneBy([
                                        'owner' => $resolvedConnection->getOwner(),
                                        'isActive' => true
                                    ]);
                                    if ($aiSetting) {
                                        $aiResponse = $this->aiAgentService->generateResponse($msgBody, $aiSetting, $resolvedConnection, (string)$subscriber->getId(), 'facebook');
                                        if ($aiResponse) {
                                            try {
                                                // Parse [ATTACH_IMAGE: <url>] from AI Response
                                                $imageUrls = [];
                                                if (preg_match_all('/\[ATTACH_IMAGE:\s*(.+?)\]/i', $aiResponse, $matches)) {
                                                    $imageUrls = $matches[1];
                                                    $aiResponse = preg_replace('/\[ATTACH_IMAGE:\s*(.+?)\]/i', '', $aiResponse);
                                                    $aiResponse = trim($aiResponse);
                                                }

                                                // Send Images
                                                foreach ($imageUrls as $url) {
                                                    $url = trim($url);
                                                    if (!empty($url)) {
                                                        try {
                                                            $this->facebookService->sendMediaMessage($subscriber->getPsid(), 'image', $url, $resolvedConnection);
                                                        } catch (\Exception $e) {
                                                            // Continue if image fails
                                                        }
                                                    }
                                                }

                                                if (!empty($aiResponse)) {
                                                    $response = $this->facebookService->sendMessage($subscriber->getPsid(), $aiResponse, $resolvedConnection);
                                                    $metaMessageId = $response['message_id'] ?? null;
    
                                                    $outboundMsg = new Message();
                                                    $outboundMsg->setSubscriber($subscriber);
                                                    $outboundMsg->setDirection('outbound');
                                                    $outboundMsg->setStatus('sent');
                                                    $outboundMsg->setType('text');
                                                    $outboundMsg->setContent($aiResponse);
                                                    $outboundMsg->setMetaMessageId($metaMessageId);
    
                                                    $this->entityManager->persist($outboundMsg);
                                                }
                                            } catch (\Exception $sendEx) {
                                                file_put_contents(
                                                    $this->getParameter('kernel.project_dir') . '/var/Facebook_webhook.log',
                                                    date('Y-m-d H:i:s') . " - AI Send Error: " . $sendEx->getMessage() . PHP_EOL,
                                                    FILE_APPEND
                                                );
                                            }
                                        }
                                    }
                                } else {
                                    // Clear if they say something else and no preset is configured
                                    if ($subscriber->getAssignedFacebookFlow()) {
                                        $subscriber->setAssignedFacebookFlow(null);
                                        $this->entityManager->persist($subscriber);
                                    }
                                }
                            }
                        }

                        // Update subscriber timestamp so it jumps to top of inbox
                        $subscriber->setUpdatedAt(new \DateTime('now', new \DateTimeZone('UTC')));
                    }
                }

                $this->entityManager->flush();
            }

            // Forward to Instagram controller to handle IG events present in the same webhook
            if ($igWebhookController) {
                try {
                    $igWebhookController->handleWebhook($request);
                } catch (\Throwable $e) {
                    file_put_contents($this->getParameter('kernel.project_dir') . '/var/facebook_webhook.log', date('Y-m-d H:i:s') . " - Error forwarding to IG Webhook: " . $e->getMessage() . PHP_EOL . $e->getTraceAsString() . PHP_EOL, FILE_APPEND);
                }
            }

            // Always return 200 to acknowledge receipt
            return new JsonResponse(['status' => 'success'], 200);
        }

        return new JsonResponse(['status' => 'not found'], 404);
    }

    private function handleCommentAddition(array $value, FacebookConnection $connection): void
    {
        $pageId = $connection->getPageId();
        // Facebook sends sender info as from.id/from.name in real webhooks,
        // but some documentation shows sender_id/sender_name — handle both.
        $senderId = $value['sender_id'] ?? $value['from']['id'] ?? null;
        
        // Skip comments made by the page itself
        if (!$senderId || $senderId === $pageId) {
            return;
        }

        $commentId = $value['comment_id'] ?? null;
        $postId = $value['post_id'] ?? null;
        $commentText = $value['message'] ?? '';
        $senderName = $value['sender_name'] ?? $value['from']['name'] ?? '';

        if (!$commentId || !$postId) {
            return;
        }

        // If the subscriber already exists but has no name or their name is their PSID, update it from this comment webhook
        if ($senderId && !empty($senderName)) {
            $existingSubscriber = $this->entityManager->getRepository(Subscriber::class)->findOneBy([
                'psid' => $senderId,
                'facebookConnection' => $connection,
            ]);
            if ($existingSubscriber && (!$existingSubscriber->getName() || $existingSubscriber->getName() === $senderId)) {
                $existingSubscriber->setName($senderName);
                $this->entityManager->flush();
            }
        }

        // 1. Fetch settings (post-specific first, then page-specific, fallback to defaults)
        $automationRepo = $this->entityManager->getRepository(\App\Entity\FacebookCommentAutomation::class);
        $projectDir = $this->getParameter('kernel.project_dir');

        // Note: the webhook $postId can sometimes be in format "pageId_postId", so we need to match carefully
        $qb = $automationRepo->createQueryBuilder('a')
            ->where('a.facebookConnection = :conn')
            ->andWhere('a.postId IS NOT NULL')
            ->setParameter('conn', $connection);
            
        $postAutomations = $qb->getQuery()->getResult();
        $postSettings = null;

        // Try to find the internal post ID mapped to this fbPostId
        $internalPostId = null;
        $postsCache = $connection->getPostsCache() ?: [];
        $cachedPosts = $postsCache['posts'] ?? [];
        foreach ($cachedPosts as $p) {
            if (($p['fbPostId'] ?? '') === $postId || ($p['id'] ?? '') === $postId) {
                $internalPostId = $p['id'] ?? null;
                break;
            }
        }
        
        foreach ($postAutomations as $auto) {
            $dbPostId = $auto->getPostId();
            if ($dbPostId === $postId || ($internalPostId && $dbPostId === $internalPostId) || str_ends_with($postId, '_' . $dbPostId) || str_ends_with($dbPostId, '_' . $postId)) {
                $postSettings = $auto->getSettingsArray();
                break;
            }
        }

        $pageSettings = null;
        if (!$postSettings) {
            $pageAutomation = $automationRepo->findOneBy([
                'facebookConnection' => $connection,
                'postId' => null
            ]);
            if ($pageAutomation) {
                $pageSettings = $pageAutomation->getSettingsArray();
            }
        }

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
            'imageReplyUrl' => '',
            'videoReplyUrl' => '',
            'filterMatchType' => 'exact',
            'filterWords' => ''
        ];

        $settings = array_merge($defaultCommentSettings, $postSettings ?? $pageSettings ?? []);
        file_put_contents($projectDir . '/var/facebook_webhook.log', date('Y-m-d H:i:s') . " - Computed Settings: " . json_encode($settings) . PHP_EOL, FILE_APPEND);

        // 2. Offensive comment moderation
        $isOffensive = false;
        if (!empty($settings['offensiveKeywords'])) {
            $keywords = array_filter(array_map('trim', explode(',', strtolower($settings['offensiveKeywords']))));
            $commentTextLower = strtolower($commentText);
            foreach ($keywords as $kw) {
                if ($kw !== '' && str_contains($commentTextLower, $kw)) {
                    $isOffensive = true;
                    break;
                }
            }
        }

        if ($isOffensive) {
            try {
                if (($settings['hideOrDelete'] ?? 'hide') === 'delete') {
                    $this->facebookService->deleteComment($commentId, $connection);
                } else {
                    $this->facebookService->hideComment($commentId, $connection);
                }
            } catch (\Exception $e) {
                file_put_contents($projectDir . '/var/facebook_webhook.log', date('Y-m-d H:i:s') . " - Error moderating offensive comment " . $commentId . ": " . $e->getMessage() . PHP_EOL . $e->getTraceAsString() . PHP_EOL, FILE_APPEND);
            }

            if (!empty($settings['offensivePrivateReplyFlowId'])) {
                $flow = $this->entityManager->getRepository(\App\Entity\FacebookBotFlow::class)->find($settings['offensivePrivateReplyFlowId']);
                if ($flow && $flow->isActive()) {
                    $subscriber = $this->getOrCreateSubscriber($senderId, $connection, $senderName);
                    $this->WhatsappBotFlowExecutor->execute($flow, $subscriber, null, $commentId);
                }
            }
            return;
        }

        // 3. Prevent multiple replies if sendReplyMultipleTimes is false
        $repliedComments = $connection->getRepliedCommentsCache() ?: [];

        if (empty($settings['sendReplyMultipleTimes']) && in_array($commentId, $repliedComments)) {
            return;
        }

        // 4. Handle comment reply if enabled
        if (!empty($settings['enableCommentReply'])) {
            $replied = false;
            $replyText = '';
            $attachmentUrl = null;
            $privateReplyFlowId = null;

            // Fetch user first & last name for token swapping
            if (empty($senderName)) {
                $senderName = $this->fetchUserProfile($senderId, $connection) ?: '';
            }
            $parts = explode(' ', $senderName, 2);
            $firstName = $parts[0] ?? '';
            $lastName = $parts[1] ?? '';

            $mode = $settings['automationMode'] ?? 'generic';

            if ($mode === 'ai') {
                $aiSetting = $this->entityManager->getRepository(\App\Entity\AiSetting::class)->findOneBy([
                    'owner' => $connection->getOwner(),
                    'isActive' => true
                ]);
                if ($aiSetting) {
                    $originalContext = $connection->getActiveContext();
                    $aiContextId = $settings['aiContextId'] ?? null;
                    if ($aiContextId) {
                        $aiContext = $this->entityManager->getRepository(\App\Entity\AiContext::class)->find($aiContextId);
                        if ($aiContext) {
                            $connection->setActiveContext($aiContext);
                        }
                    }

                    $replyText = $this->aiAgentService->generateResponse($commentText, $aiSetting, $connection, (string)$senderId, 'facebook');
                    
                    // Strip any [ATTACH_IMAGE] tags from comment replies
                    if ($replyText) {
                        $replyText = preg_replace('/\[ATTACH_IMAGE:\s*(.+?)\]/i', '', $replyText);
                        $replyText = trim($replyText);
                    }
                    
                    // Restore original context
                    $connection->setActiveContext($originalContext);

                    if (!empty($replyText)) {
                        $replied = true;
                        $privateReplyFlowId = $settings['privateReplyFlowId'] ?? null;
                    }
                }
            } elseif ($mode === 'generic') {
                $replyText = str_replace(
                    ['{first_name}', '{last_name}'],
                    [$firstName, $lastName],
                    $settings['commentReplyText'] ?? ''
                );
                $attachmentUrl = !empty($settings['imageReplyUrl']) ? $settings['imageReplyUrl'] : (!empty($settings['videoReplyUrl']) ? $settings['videoReplyUrl'] : null);
                $privateReplyFlowId = !empty($settings['privateReplyFlowId']) ? $settings['privateReplyFlowId'] : null;
                if (!empty($replyText) || !empty($attachmentUrl) || !empty($privateReplyFlowId)) {
                    $replied = true;
                }
            } elseif ($mode === 'filter') {
                if (isset($settings['filterRules']) && is_array($settings['filterRules']) && !empty($settings['filterRules'])) {
                    $commentTextLower = strtolower($commentText);
                    foreach ($settings['filterRules'] as $rule) {
                        $filterWords = array_filter(array_map('trim', explode(',', strtolower($rule['filterWords'] ?? ''))));
                        $matchType = $rule['filterMatchType'] ?? 'exact';
                        $matched = false;
                        foreach ($filterWords as $word) {
                            if ($word !== '') {
                                if ($matchType === 'exact') {
                                    if (trim($commentTextLower) === $word) {
                                        $matched = true;
                                        break;
                                    }
                                } else { // partial
                                    if (str_contains($commentTextLower, $word)) {
                                        $matched = true;
                                        break;
                                    }
                                }
                            }
                        }
                        if ($matched) {
                            $replyText = str_replace(
                                ['{first_name}', '{last_name}'],
                                [$firstName, $lastName],
                                $rule['commentReplyText'] ?? ''
                            );
                            $attachmentUrl = !empty($rule['imageReplyUrl']) ? $rule['imageReplyUrl'] : (!empty($rule['videoReplyUrl']) ? $rule['videoReplyUrl'] : null);
                            $privateReplyFlowId = !empty($rule['privateReplyFlowId']) ? $rule['privateReplyFlowId'] : null;
                            if (!empty($replyText) || !empty($attachmentUrl) || !empty($privateReplyFlowId)) {
                                $replied = true;
                            }
                            break;
                        }
                    }
                    // If no rule matched, execute fallback if configured
                    if (!$replied && !empty($settings['fallbackSettings'])) {
                        $fallback = $settings['fallbackSettings'];
                        $replyText = str_replace(
                            ['{first_name}', '{last_name}'],
                            [$firstName, $lastName],
                            $fallback['commentReplyText'] ?? ''
                        );
                        $attachmentUrl = !empty($fallback['imageReplyUrl']) ? $fallback['imageReplyUrl'] : (!empty($fallback['videoReplyUrl']) ? $fallback['videoReplyUrl'] : null);
                        $privateReplyFlowId = !empty($fallback['privateReplyFlowId']) ? $fallback['privateReplyFlowId'] : null;
                        if (!empty($replyText) || !empty($attachmentUrl) || !empty($privateReplyFlowId)) {
                            $replied = true;
                        }
                    }
                } else {
                    // Backwards compatibility for single filter settings
                    $filterWords = array_filter(array_map('trim', explode(',', strtolower($settings['filterWords'] ?? ''))));
                    $commentTextLower = strtolower($commentText);
                    $matchType = $settings['filterMatchType'] ?? 'exact';
                    
                    $matched = false;
                    foreach ($filterWords as $word) {
                        if ($word !== '') {
                            if ($matchType === 'exact') {
                                if (trim($commentTextLower) === $word) {
                                    $matched = true;
                                    break;
                                }
                            } else { // partial
                                if (str_contains($commentTextLower, $word)) {
                                    $matched = true;
                                    break;
                                }
                            }
                        }
                    }

                    if ($matched) {
                        $replyText = str_replace(
                            ['{first_name}', '{last_name}'],
                            [$firstName, $lastName],
                            $settings['commentReplyText'] ?? ''
                        );
                        $attachmentUrl = !empty($settings['imageReplyUrl']) ? $settings['imageReplyUrl'] : (!empty($settings['videoReplyUrl']) ? $settings['videoReplyUrl'] : null);
                        $privateReplyFlowId = !empty($settings['privateReplyFlowId']) ? $settings['privateReplyFlowId'] : null;
                        if (!empty($replyText) || !empty($attachmentUrl) || !empty($privateReplyFlowId)) {
                            $replied = true;
                        }
                    }
                }
            }

            if ($replied) {
                $replySuccess = true;
                if (!empty($replyText) || !empty($attachmentUrl)) {
                    try {
                        $this->facebookService->replyToComment($commentId, $replyText, $attachmentUrl, $connection);
                    } catch (\Exception $e) {
                        $replySuccess = false;
                        file_put_contents($projectDir . '/var/facebook_webhook.log', date('Y-m-d H:i:s') . " - Error replying to comment " . $commentId . ": " . $e->getMessage() . PHP_EOL . $e->getTraceAsString() . PHP_EOL, FILE_APPEND);
                    }
                }

                if ($replySuccess) {
                    $repliedComments[] = $commentId;
                    $connection->setRepliedCommentsCache($repliedComments);
                    $this->entityManager->flush();
                }

                // Send Private Reply message flow if configured
                $flowId = $privateReplyFlowId;
                if ($flowId) {
                    $flow = $this->entityManager->getRepository(\App\Entity\FacebookBotFlow::class)->find($flowId);
                    if ($flow && $flow->isActive()) {
                        $subscriber = $this->getOrCreateSubscriber($senderId, $connection, $senderName);
                        $this->WhatsappBotFlowExecutor->execute($flow, $subscriber, null, $commentId);
                    }
                }

                // Hide comment after reply if configured
                if (!empty($settings['hideCommentAfterReply'])) {
                    try {
                        $this->facebookService->hideComment($commentId, $connection);
                    } catch (\Exception $e) {
                        file_put_contents($projectDir . '/var/facebook_webhook.log', date('Y-m-d H:i:s') . " - Error hiding comment " . $commentId . " after reply: " . $e->getMessage() . PHP_EOL . $e->getTraceAsString() . PHP_EOL, FILE_APPEND);
                    }
                }
            }
        }
    }

    private function getOrCreateSubscriber(string $psid, FacebookConnection $connection, string $senderName = ''): Subscriber
    {
        $subscriber = $this->entityManager->getRepository(Subscriber::class)->findOneBy([
            'psid' => $psid,
            'facebookConnection' => $connection,
        ]);

        if (!$subscriber) {
            $subscriber = new Subscriber();
            $subscriber->setChannel('facebook');
            $subscriber->setPsid($psid);
            $subscriber->setFacebookConnection($connection);
            
            if (empty($senderName)) {
                $senderName = $this->fetchUserProfile($psid, $connection) ?: '';
            }
            if ($senderName !== '') {
                $subscriber->setName($senderName);
            }
            
            // Set update time
            $subscriber->setUpdatedAt(new \DateTime('now', new \DateTimeZone('UTC')));

            $this->entityManager->persist($subscriber);
            $this->entityManager->flush();
        } elseif (!empty($senderName) && (!$subscriber->getName() || $subscriber->getName() === $psid)) {
            // Update name on existing subscribers that are still unnamed
            $subscriber->setName($senderName);
            $this->entityManager->flush();
        }

        return $subscriber;
    }

    /**
     * Fetch user profile name from Facebook Graph API.
     */
    private function fetchUserProfile(string $psid, ?FacebookConnection $connection): ?string
    {
        if (!$connection) {
            return null;
        }

        try {
            $accessToken = $this->facebookService->decryptToken($connection->getEncryptedPageAccessToken());
            $response = $this->httpClient->request('GET', "https://graph.facebook.com/v21.0/{$psid}", [
                'query' => [
                    'fields' => 'first_name,last_name,name',
                    'access_token' => $accessToken,
                ],
            ]);

            if ($response->getStatusCode() < 400) {
                $data = $response->toArray();
                $firstName = $data['first_name'] ?? '';
                $lastName = $data['last_name'] ?? '';
                $fullName = trim("{$firstName} {$lastName}");
                // Fallback to 'name' field if first/last are empty
                return $fullName ?: ($data['name'] ?? null);
            }
        } catch (\Exception $e) {
            // Profile lookup can fail for some PSIDs — this is expected
        }

        return null;
    }

    private function executeFacebookActionButton(\App\Entity\FacebookActionButton $action, Subscriber $subscriber): void
    {
        if ($action->getReplyType() === 'flow') {
            $flow = $action->getBotFlow();
            if ($flow && $flow->isActive()) {
                $subscriber->setAssignedFacebookFlow($flow);
                $this->entityManager->persist($subscriber);
                $this->entityManager->flush();
                $this->WhatsappBotFlowExecutor->execute($flow, $subscriber);
            }
        } elseif ($action->getReplyType() === 'text') {
            $replyText = $action->getReplyText();
            if (!empty($replyText)) {
                $this->facebookService->sendMessage($subscriber->getPsid(), $replyText, $action->getFacebookConnection());
            }
        }
    }
}
