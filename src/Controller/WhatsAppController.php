<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class WhatsAppController extends AbstractController
{
    private string $verifyToken;
    private string $accessToken;
    private string $phoneNumberId;
    private HttpClientInterface $httpClient;

    public function __construct(
        #[Autowire('%env(WHATSAPP_VERIFY_TOKEN)%')] string $verifyToken,
        #[Autowire('%env(WHATSAPP_ACCESS_TOKEN)%')] string $accessToken,
        #[Autowire('%env(WHATSAPP_PHONE_NUMBER_ID)%')] string $phoneNumberId,
        HttpClientInterface $httpClient
    ) {
        $this->verifyToken = $verifyToken;
        $this->accessToken = $accessToken;
        $this->phoneNumberId = $phoneNumberId;
        $this->httpClient = $httpClient;
    }

    #[Route('/webhook/whatsapp', name: 'whatsapp_webhook_verify', methods: ['GET'])]
    public function verifyWebhook(Request $request): Response
    {
        $mode = $request->query->get('hub_mode', $request->query->get('hub.mode'));
        $token = $request->query->get('hub_verify_token', $request->query->get('hub.verify_token'));
        $challenge = $request->query->get('hub_challenge', $request->query->get('hub.challenge'));

        if ($mode && $token) {
            if ($mode === 'subscribe' && $token === $this->verifyToken) {
                return new Response($challenge, 200);
            }
            return new Response('Forbidden', 403);
        }

        return new Response('Bad Request', 400);
    }

    #[Route('/webhook/whatsapp', name: 'whatsapp_webhook_handle', methods: ['POST'])]
    public function handleWebhook(Request $request): JsonResponse
    {
        $content = json_decode($request->getContent(), true);

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
                        $msgBody = $message['text']['body'] ?? '';

                        // For testing, reply to the user automatically to verify it works
                        if ($msgBody) {
                            $this->sendMessage($from, "Hello! We received your message: \"{$msgBody}\" from OpenSquadron webhook test.");
                        }
                    }
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
            $response = $this->sendMessage($to, "Hello from OpenSquadron! This is a test message to verify the connection.");
            return new JsonResponse(['status' => 'success', 'data' => $response]);
        } catch (\Exception $e) {
            return new JsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    private function sendMessage(string $to, string $text): array
    {
        $url = "https://graph.facebook.com/v21.0/{$this->phoneNumberId}/messages";
        
        $response = $this->httpClient->request('POST', $url, [
            'headers' => [
                'Authorization' => "Bearer {$this->accessToken}",
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $to,
                'type' => 'text',
                'text' => [
                    'preview_url' => false,
                    'body' => $text,
                ]
            ]
        ]);

        return $response->toArray(); // Will throw exception if response is not 2xx
    }
}
