<?php

namespace App\Controller;

use App\Service\WhatsappBotFlowExecutor;
use App\Service\WhatsAppConnectionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\Subscriber;
use App\Entity\Message;
use App\Entity\WhatsAppConnection;
use App\Entity\WhatsappActionButton;
use App\Security\Voter\TeamPermissionVoter;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(TeamPermissionVoter::PERM_WHATSAPP_MANAGE)]
class WhatsAppController extends AbstractController
{
    private string $envVerifyToken;
    private string $envAccessToken;
    private string $phoneNumberId;
    private HttpClientInterface $httpClient;
    private WhatsAppConnectionService $whatsappService;

    public function __construct(
        #[Autowire('%env(WHATSAPP_VERIFY_TOKEN)%')] string $verifyToken,
        #[Autowire('%env(WHATSAPP_ACCESS_TOKEN)%')] string $accessToken,
        #[Autowire('%env(WHATSAPP_PHONE_NUMBER_ID)%')] string $phoneNumberId,
        HttpClientInterface $httpClient,
        WhatsAppConnectionService $whatsappService,
        private EntityManagerInterface $entityManager,
        private WhatsappBotFlowExecutor $WhatsappBotFlowExecutor,
        private \App\Service\AiAgentService $aiAgentService,
        private \App\Service\TenantDatabaseService $tenantDbService,
        private \App\Service\TenantContext $tenantContext
    ) {
        $this->envVerifyToken = $verifyToken;
        $this->envAccessToken = $accessToken;
        $this->phoneNumberId = $phoneNumberId;
        $this->httpClient = $httpClient;
        $this->whatsappService = $whatsappService;
    }


    private function getVerifyToken(): string
    {
        $connection = $this->whatsappService->getConnection();
        if ($connection && $connection->getVerifyToken()) {
            return $connection->getVerifyToken();
        }
        return $this->envVerifyToken;
    }

    #[Route('/webhook/whatsapp', name: 'whatsapp_webhook_verify', methods: ['GET'])]
    public function verifyWebhook(Request $request): Response
    {
        $mode = $request->query->get('hub_mode', $request->query->get('hub.mode'));
        $token = $request->query->get('hub_verify_token', $request->query->get('hub.verify_token'));
        $challenge = $request->query->get('hub_challenge', $request->query->get('hub.challenge'));

        if ($token) {
            $this->tenantContext->disableTenantFilter();
            $connection = $this->entityManager->getRepository(WhatsAppConnection::class)->findOneBy(['verifyToken' => $token]);
            if ($connection) {
                $owner = $connection->getOwner();
                if ($owner) {
                    $this->tenantContext->setCurrentOwner($owner);
                }
            }
        }

        $expectedToken = $this->getVerifyToken();

        if ($mode && $token) {
            if ($mode === 'subscribe' && $token === $expectedToken) {
                return new Response($challenge, 200);
            }
            return new Response('Forbidden', 403);
        }

        return new Response('Bad Request', 400);
    }


    #[Route('/webhook/whatsapp', name: 'whatsapp_webhook_handle', methods: ['POST'])]
    public function handleWebhook(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        // Log incoming payload for debugging
        file_put_contents($this->getParameter('kernel.project_dir') . '/var/webhook.log', date('Y-m-d H:i:s') . " - " . $payload . PHP_EOL, FILE_APPEND);

        $content = json_decode($payload, true);

        if (!$content) {
            return new JsonResponse(['status' => 'invalid payload'], 400);
        }

        // WhatsApp sends the data in this structure
        if (isset($content['object']) && $content['object'] === 'whatsapp_business_account') {
            foreach ($content['entry'] as $entry) {
                if (isset($entry['changes'][0]['value']['messages'])) {
                    $changes = $entry['changes'][0]['value'];
                    $messages = $changes['messages'];

                    // Resolve the correct connection by the phone_number_id in the payload
                    $incomingPhoneNumberId = $changes['metadata']['phone_number_id'] ?? null;
                    
                    $this->tenantContext->disableTenantFilter();

                    $resolvedConnection = null;
                    if ($incomingPhoneNumberId) {
                        $resolvedConnection = $this->entityManager->getRepository(WhatsAppConnection::class)->findOneBy(['phoneNumberId' => $incomingPhoneNumberId]);
                    }
                    if (!$resolvedConnection) {
                        $resolvedConnection = $this->whatsappService->getDefaultConnection();
                    }

                    if ($resolvedConnection) {
                        $owner = $resolvedConnection->getOwner();
                        if ($owner) {
                            $this->tenantContext->setCurrentOwner($owner);
                        }
                    }

                    foreach ($messages as $message) {
                        $from = $message['from']; // The sender's phone number
                        $msgBody = '';
                        $msgType = $message['type'] ?? 'text';
                        $mediaUrl = null;
                        
                        if ($msgType === 'text') {
                            $msgBody = $message['text']['body'] ?? '';
                        } elseif ($msgType === 'image') {
                            $mediaId = $message['image']['id'] ?? null;
                            if ($mediaId) {
                                $uploadDir = $this->getParameter('kernel.project_dir') . '/public/uploads/whatsapp_media';
                                $mediaUrl = $this->whatsappService->downloadMedia($mediaId, $uploadDir);
                            }
                            $msgBody = $message['image']['caption'] ?? '';
                        } elseif ($msgType === 'audio') {
                            $mediaId = $message['audio']['id'] ?? null;
                            if ($mediaId) {
                                $uploadDir = $this->getParameter('kernel.project_dir') . '/public/uploads/whatsapp_media';
                                $mediaUrl = $this->whatsappService->downloadMedia($mediaId, $uploadDir);
                            }
                        }

                        $metaMessageId = $message['id'] ?? null;

                        if ($msgBody !== '' || $mediaUrl !== null) {
                            $isNewSubscriber = false;
                            $subscriber = $this->entityManager->getRepository(Subscriber::class)->findOneBy([
                                'phoneNumber' => $from,
                                'whatsAppConnection' => $resolvedConnection
                            ]);
                            if (!$subscriber) {
                                $subscriber = new Subscriber();
                                $subscriber->setPhoneNumber($from);
                                $subscriber->setWhatsAppConnection($resolvedConnection);
                                
                                $name = $entry['changes'][0]['value']['contacts'][0]['profile']['name'] ?? null;
                                if ($name) {
                                    $subscriber->setName($name);
                                }
                                $this->entityManager->persist($subscriber);
                                $isNewSubscriber = true;
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
                            
                            // Check for Automations (Bot Flows) or waiting state interception.
                            $isResumed = false;
                            
                            // Check for opt-out/opt-in first (Unsubscribe / Resubscribe action buttons)
                            $msgBodyLower = strtolower(trim($msgBody));
                            $stopWords = ['stop', 'unsubscribe', 'cancel', 'quit', 'optout', 'opt-out'];
                            $startWords = ['start', 'subscribe', 'unstop', 'optin', 'opt-in'];

                            if (in_array($msgBodyLower, $stopWords)) {
                                $subscriber->setStatus('unsubscribed');
                                $this->entityManager->persist($subscriber);
                                $this->entityManager->flush();

                                $actionButton = $this->entityManager->getRepository(WhatsappActionButton::class)
                                    ->findOneBy([
                                        'whatsAppConnection' => $resolvedConnection,
                                        'buttonKey' => 'unsubscribe',
                                        'isEnabled' => true
                                    ]);
                                if ($actionButton) {
                                    $this->executeWhatsappActionButton($actionButton, $subscriber);
                                }
                                $isResumed = true;
                            } elseif (in_array($msgBodyLower, $startWords)) {
                                $subscriber->setStatus('active');
                                $this->entityManager->persist($subscriber);
                                $this->entityManager->flush();

                                $actionButton = $this->entityManager->getRepository(WhatsappActionButton::class)
                                    ->findOneBy([
                                        'whatsAppConnection' => $resolvedConnection,
                                        'buttonKey' => 'resubscribe',
                                        'isEnabled' => true
                                    ]);
                                if ($actionButton) {
                                    $this->executeWhatsappActionButton($actionButton, $subscriber);
                                }
                                $isResumed = true;
                            }

                            // If subscriber is unsubscribed, ignore everything else
                            if (!$isResumed && $subscriber->getStatus() === 'unsubscribed') {
                                $isResumed = true;
                            }

                            if (!$isResumed && $msgType === 'text' && $msgBody !== '') {
                                $attrs = $subscriber->getCustomAttributes();
                                $waitingForInput = $attrs['_waiting_for_input'] ?? null;
                                $waitingNodeId = $attrs['_waiting_node_id'] ?? null;
                                $waitingFlowId = $attrs['_waiting_flow_id'] ?? null;
                                $waitingFlowType = $attrs['_waiting_flow_type'] ?? null;

                                if (!empty($waitingForInput) && $waitingFlowType === 'whatsapp' && !empty($waitingFlowId)) {
                                    // Save incoming response text in custom attributes under custom field key
                                    $attrs[$waitingForInput] = $msgBody;
                                    
                                    // Clear waiting state keys
                                    unset($attrs['_waiting_for_input'], $attrs['_waiting_node_id'], $attrs['_waiting_flow_id'], $attrs['_waiting_flow_type']);
                                    $subscriber->setCustomAttributes($attrs);
                                    $this->entityManager->flush();

                                    // Find flow
                                    $flow = $this->entityManager->getRepository(\App\Entity\WhatsappBotFlow::class)->find($waitingFlowId);
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
                                            // Resume flow execution starting from the next node
                                            $this->WhatsappBotFlowExecutor->execute($flow, $subscriber, $nextNodeId);
                                        }
                                    }
                                    $isResumed = true;
                                }
                            }

                            // Check system events (Get Started, Human Escalation, Resume Bot)
                            if (!$isResumed && $isNewSubscriber) {
                                $actionButton = $this->entityManager->getRepository(WhatsappActionButton::class)
                                    ->findOneBy([
                                        'whatsAppConnection' => $resolvedConnection,
                                        'buttonKey' => 'get-started',
                                        'isEnabled' => true
                                    ]);
                                if ($actionButton) {
                                    $this->executeWhatsappActionButton($actionButton, $subscriber);
                                    $isResumed = true;
                                }
                            }

                            if (!$isResumed && $msgType === 'text' && $msgBody !== '') {
                                if ($msgBody === 'ESCALATE_HUMAN_TRIGGER') {
                                    $subscriber->setStatus('paused');
                                    $this->entityManager->persist($subscriber);
                                    $this->entityManager->flush();

                                    $actionButton = $this->entityManager->getRepository(WhatsappActionButton::class)
                                        ->findOneBy([
                                            'whatsAppConnection' => $resolvedConnection,
                                            'buttonKey' => 'chat-with-human',
                                            'isEnabled' => true
                                        ]);
                                    if ($actionButton) {
                                        $this->executeWhatsappActionButton($actionButton, $subscriber);
                                        $isResumed = true;
                                    }
                                } elseif ($msgBody === 'RESUME_BOT_TRIGGER') {
                                    $subscriber->setStatus('active');
                                    $this->entityManager->persist($subscriber);
                                    $this->entityManager->flush();

                                    $actionButton = $this->entityManager->getRepository(WhatsappActionButton::class)
                                        ->findOneBy([
                                            'whatsAppConnection' => $resolvedConnection,
                                            'buttonKey' => 'chat-with-bot',
                                            'isEnabled' => true
                                        ]);
                                    if ($actionButton) {
                                        $this->executeWhatsappActionButton($actionButton, $subscriber);
                                        $isResumed = true;
                                    }
                                }
                            }

                            // Check location reply
                            if (!$isResumed && $msgType === 'location') {
                                $actionButton = $this->entityManager->getRepository(WhatsappActionButton::class)
                                    ->findOneBy([
                                        'whatsAppConnection' => $resolvedConnection,
                                        'buttonKey' => 'location-reply',
                                        'isEnabled' => true
                                    ]);
                                if ($actionButton) {
                                    $this->executeWhatsappActionButton($actionButton, $subscriber);
                                    $isResumed = true;
                                }
                            }

                            // If subscriber is paused, ignore bot keyword flows
                            if (!$isResumed && $subscriber->getStatus() === 'paused') {
                                $isResumed = true;
                            }

                            if ($isResumed) {
                                // Already handled
                            } elseif ($msgType === 'text' && $msgBody !== '') {
                                $flows = $this->entityManager
                                    ->getRepository(\App\Entity\WhatsappBotFlow::class)
                                    ->findBy([
                                        'isActive' => true,
                                        'whatsAppConnection' => $resolvedConnection,
                                    ]);

                                $matched = false;
                                foreach ($flows as $candidate) {
                                    if ($candidate->matches($msgBody)) {
                                        $this->WhatsappBotFlowExecutor->execute($candidate, $subscriber);
                                        $matched = true;
                                        break;
                                    }
                                }

                                if (!$matched) {
                                    // Check No Match Action Button first!
                                    $actionButton = $this->entityManager->getRepository(WhatsappActionButton::class)
                                        ->findOneBy([
                                            'whatsAppConnection' => $resolvedConnection,
                                            'buttonKey' => 'no-match',
                                            'isEnabled' => true
                                        ]);
                                    if ($actionButton) {
                                        $this->executeWhatsappActionButton($actionButton, $subscriber);
                                    } elseif ($resolvedConnection && $resolvedConnection->isAiActive()) {
                                        $aiSetting = $this->entityManager->getRepository(\App\Entity\AiSetting::class)->findOneBy([
                                            'owner' => $resolvedConnection->getOwner(),
                                            'isActive' => true
                                        ]);
                                        if ($aiSetting) {
                                            $aiResponse = $this->aiAgentService->generateResponse($msgBody, $aiSetting, $resolvedConnection);
                                            if ($aiResponse) {
                                                try {
                                                    $response = $this->whatsappService->sendMessage($subscriber->getPhoneNumber(), $aiResponse, $resolvedConnection);
                                                    $metaMessageId = $response['messages'][0]['id'] ?? null;

                                                    $outboundMsg = new Message();
                                                    $outboundMsg->setSubscriber($subscriber);
                                                    $outboundMsg->setDirection('outbound');
                                                    $outboundMsg->setStatus('sent');
                                                    $outboundMsg->setType('text');
                                                    $outboundMsg->setContent($aiResponse);
                                                    $outboundMsg->setMetaMessageId($metaMessageId);

                                                    $this->entityManager->persist($outboundMsg);
                                                } catch (\Exception $sendEx) {
                                                    file_put_contents(
                                                        $this->getParameter('kernel.project_dir') . '/var/webhook.log',
                                                        date('Y-m-d H:i:s') . " - AI Send Error: " . $sendEx->getMessage() . PHP_EOL,
                                                        FILE_APPEND
                                                    );
                                                }
                                            }
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
            }
            // Always return 200 to acknowledge receipt of standard webhook events
            return new JsonResponse(['status' => 'success'], 200);
        }

        return new JsonResponse(['status' => 'not found'], 404);
    }

    #[Route('/whatsapp/test', name: 'whatsapp_test_send', methods: ['GET'])]
    public function testSendMessage(Request $request): JsonResponse
    {
        $to = $request->query->get('to');
        if (!$to) {
            return new JsonResponse(['error' => 'Please provide a "to" parameter with the target phone number with country code.'], 400);
        }

        try {
            $response = $this->whatsappService->sendMessage($to, "Hello from OpenSquadron! This is a test message to verify the connection.");
            return new JsonResponse(['status' => 'success', 'data' => $response]);
        } catch (\Exception $e) {
            return new JsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    private function executeWhatsappActionButton(WhatsappActionButton $action, Subscriber $subscriber): void
    {
        if ($action->getReplyType() === 'flow') {
            $flow = $action->getBotFlow();
            if ($flow && $flow->isActive()) {
                $subscriber->setAssignedWhatsappFlow($flow);
                $this->entityManager->persist($subscriber);
                $this->entityManager->flush();
                $this->WhatsappBotFlowExecutor->execute($flow, $subscriber);
            }
        } elseif ($action->getReplyType() === 'text') {
            $replyText = $action->getReplyText();
            if (!empty($replyText)) {
                try {
                    $response = $this->whatsappService->sendMessage($subscriber->getPhoneNumber(), $replyText, $action->getWhatsAppConnection());
                    $metaMessageId = $response['messages'][0]['id'] ?? null;

                    $outboundMsg = new Message();
                    $outboundMsg->setSubscriber($subscriber);
                    $outboundMsg->setDirection('outbound');
                    $outboundMsg->setStatus('sent');
                    $outboundMsg->setType('text');
                    $outboundMsg->setContent($replyText);
                    $outboundMsg->setMetaMessageId($metaMessageId);

                    $this->entityManager->persist($outboundMsg);
                    $this->entityManager->flush();
                } catch (\Exception $sendEx) {
                    file_put_contents(
                        $this->getParameter('kernel.project_dir') . '/var/webhook.log',
                        date('Y-m-d H:i:s') . " - Action Button Send Error: " . $sendEx->getMessage() . PHP_EOL,
                        FILE_APPEND
                    );
                }
            }
        }
    }
}
