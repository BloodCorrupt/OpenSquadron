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
                $setting = $this->entityManager->getRepository(\App\Entity\FacebookSetting::class)->findOneBy(['verifyToken' => $token]);
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
                $setting = $this->entityManager->getRepository(\App\Entity\FacebookSetting::class)->findOneBy(['verifyToken' => $token]);
            }

            if ($mode === 'subscribe' && ($connection || $setting)) {
                return new Response($challenge, 200);
            }
            return new Response('Forbidden', 403);
        }

        return new Response('Bad Request', 400);
    }

    #[Route('/webhook/facebook', name: 'facebook_webhook_handle', methods: ['POST'])]
    public function handleWebhook(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        // Log incoming payload for debugging
        file_put_contents($this->getParameter('kernel.project_dir') . '/var/facebook_webhook.log', date('Y-m-d H:i:s') . " - " . $payload . PHP_EOL, FILE_APPEND);

        $content = json_decode($payload, true);

        if (!$content) {
            return new JsonResponse(['status' => 'invalid payload'], 400);
        }

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
                                } catch (\Exception $e) {
                                    file_put_contents($this->getParameter('kernel.project_dir') . '/var/facebook_webhook.log', date('Y-m-d H:i:s') . " - Error handling comment: " . $e->getMessage() . PHP_EOL, FILE_APPEND);
                                }
                            }
                        }
                    }
                }

                if (!isset($entry['messaging'])) {
                    continue;
                }

                foreach ($entry['messaging'] as $messagingEvent) {
                    // Only process message events (not deliveries, reads, etc.)
                    if (!isset($messagingEvent['message'])) {
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

                    $metaMessageId = $messagingEvent['message']['mid'] ?? null;

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
                        
                        if ($msgType === 'text' && $msgBody !== '') {
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

                        if ($isResumed) {
                            // Already resumed the flow, do not trigger keyword automations.
                        } elseif ($msgType === 'text' && $msgBody !== '' && $resolvedConnection) {
                            $flows = $this->entityManager
                                ->getRepository(\App\Entity\FacebookBotFlow::class)
                                ->findBy([
                                    'isActive' => true,
                                    'facebookConnection' => $resolvedConnection,
                                ]);

                            $matchedFlow = null;
                            foreach ($flows as $flow) {
                                if ($flow->matches($msgBody)) {
                                    $matchedFlow = $flow;
                                    break;
                                }
                            }

                            if ($matchedFlow) {
                                // Save the assigned flow
                                $subscriber->setAssignedFacebookFlow($matchedFlow);
                                $this->entityManager->persist($subscriber);

                                // Execute the flow
                                $this->WhatsappBotFlowExecutor->execute($matchedFlow, $subscriber);
                            } else {
                                // Clear if they say something else
                                if ($subscriber->getAssignedFacebookFlow()) {
                                    $subscriber->setAssignedFacebookFlow(null);
                                    $this->entityManager->persist($subscriber);
                                }
                            }
                        }

                        // Update subscriber timestamp so it jumps to top of inbox
                        $subscriber->setUpdatedAt(new \DateTime('now', new \DateTimeZone('UTC')));
                    }
                }

                $this->entityManager->flush();
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

        // 1. Fetch settings (post-specific first, then page-specific, fallback to defaults)
        $projectDir = $this->getParameter('kernel.project_dir');
        $postsFile = $projectDir . "/var/facebook_posts/conn_" . $connection->getId() . ".json";
        $postSettings = null;
        if (file_exists($postsFile)) {
            $posts = json_decode(file_get_contents($postsFile), true) ?: [];
            foreach ($posts as $post) {
                $fbPostId = $post['fbPostId'] ?? '';
                if ($fbPostId !== '' && ($fbPostId === $postId || str_ends_with($postId, $fbPostId) || str_ends_with($fbPostId, $postId))) {
                    $postSettings = $post['commentAutomationSettings'] ?? null;
                    break;
                }
            }
        }

        $settingsFile = $projectDir . "/var/facebook_bot_settings/conn_" . $connection->getId() . ".json";
        $pageSettings = null;
        if (file_exists($settingsFile)) {
            $saved = json_decode(file_get_contents($settingsFile), true) ?: [];
            $pageSettings = $saved['comment-automation'] ?? null;
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
        $repliedFile = $projectDir . "/var/facebook_bot_settings/replied_comments_" . $connection->getId() . ".json";
        if (!is_dir(dirname($repliedFile))) {
            mkdir(dirname($repliedFile), 0777, true);
        }
        $repliedComments = [];
        if (file_exists($repliedFile)) {
            $repliedComments = json_decode(file_get_contents($repliedFile), true) ?: [];
        }

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

                    $replyText = $this->aiAgentService->generateResponse($commentText, $aiSetting, $connection);
                    
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
                    file_put_contents($repliedFile, json_encode($repliedComments));
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
                    'fields' => 'first_name,last_name',
                    'access_token' => $accessToken,
                ],
            ]);

            if ($response->getStatusCode() < 400) {
                $data = $response->toArray();
                $firstName = $data['first_name'] ?? '';
                $lastName = $data['last_name'] ?? '';
                return trim("{$firstName} {$lastName}") ?: null;
            }
        } catch (\Exception $e) {
            // Log warning but keep going — name is optional
            $projectDir = $this->getParameter('kernel.project_dir');
            file_put_contents($projectDir . '/var/facebook_webhook.log', date('Y-m-d H:i:s') . " - Error fetching user profile for PSID " . $psid . ": " . $e->getMessage() . PHP_EOL, FILE_APPEND);
        }

        return null;
    }
}
