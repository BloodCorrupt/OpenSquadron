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
            // Silently fail — name is optional
        }

        return null;
    }
}
