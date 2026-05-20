<?php

namespace App\Controller;

use App\Service\BotFlowExecutor;
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
        private BotFlowExecutor $botFlowExecutor
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
                            $subscriber = $this->entityManager->getRepository(Subscriber::class)->findOneBy(['phoneNumber' => $from]);
                            if (!$subscriber) {
                                $subscriber = new Subscriber();
                                $subscriber->setPhoneNumber($from);
                                
                                $name = $entry['changes'][0]['value']['contacts'][0]['profile']['name'] ?? null;
                                if ($name) {
                                    $subscriber->setName($name);
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
                            
                            // Check for Automations (Bot Flows). The first
                            // active flow whose keyword/match-mode matches wins.
                            if ($msgType === 'text' && $msgBody !== '') {
                                $flows = $this->entityManager
                                    ->getRepository(\App\Entity\BotFlow::class)
                                    ->findBy(['isActive' => true]);

                                foreach ($flows as $candidate) {
                                    if ($candidate->matches($msgBody)) {
                                        $this->botFlowExecutor->execute($candidate, $subscriber);
                                        break;
                                    }
                                }
                            }

                            // Update subscriber timestamp so it jumps to top of inbox
                            $subscriber->setUpdatedAt(new \DateTime());
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
}
