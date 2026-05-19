<?php

namespace App\Controller;

use App\Entity\Message;
use App\Entity\Subscriber;
use App\Service\WhatsAppConnectionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class LiveChatController extends AbstractController
{
    #[Route('/admin/inbox', name: 'app_inbox', methods: ['GET'])]
    public function index(EntityManagerInterface $em): Response
    {
        // Get all subscribers ordered by the most recently updated (newest message)
        $subscribers = $em->getRepository(Subscriber::class)->findBy([], ['updatedAt' => 'DESC']);

        return $this->render('chat/inbox.html.twig', [
            'subscribers' => $subscribers,
            'activeSubscriber' => null,
            'messages' => []
        ]);
    }

    #[Route('/admin/inbox/{id}', name: 'app_inbox_chat', methods: ['GET'])]
    public function chat(Subscriber $subscriber, EntityManagerInterface $em): Response
    {
        $subscribers = $em->getRepository(Subscriber::class)->findBy([], ['updatedAt' => 'DESC']);
        $messages = $em->getRepository(Message::class)->findBy(['subscriber' => $subscriber], ['timestamp' => 'ASC']);

        return $this->render('chat/inbox.html.twig', [
            'subscribers' => $subscribers,
            'activeSubscriber' => $subscriber,
            'messages' => $messages
        ]);
    }

    #[Route('/admin/inbox/api/send', name: 'app_inbox_send', methods: ['POST'])]
    public function send(Request $request, EntityManagerInterface $em, WhatsAppConnectionService $whatsappService): JsonResponse
    {
        $subscriberId = $request->request->get('subscriber_id');
        $content = $request->request->get('content');

        if (!$subscriberId || empty(trim($content))) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid data'], 400);
        }

        $subscriber = $em->getRepository(Subscriber::class)->find($subscriberId);
        if (!$subscriber) {
            return new JsonResponse(['success' => false, 'error' => 'Subscriber not found'], 404);
        }

        try {
            // Send via Meta API
            $response = $whatsappService->sendMessage($subscriber->getPhoneNumber(), $content);

            // Create message entity
            $msg = new Message();
            $msg->setSubscriber($subscriber);
            $msg->setDirection('outbound');
            $msg->setContent($content);
            $msg->setMetaMessageId($response['messages'][0]['id'] ?? null);
            $msg->setStatus('sent');
            
            $em->persist($msg);

            // Update subscriber
            $subscriber->setUpdatedAt(new \DateTime());
            
            $em->flush();

            return new JsonResponse([
                'success' => true, 
                'message' => [
                    'id' => $msg->getId(),
                    'content' => $msg->getContent(),
                    'timestamp' => $msg->getTimestamp()->format('Y-m-d H:i:s'),
                    'direction' => 'outbound'
                ]
            ]);

        } catch (\Exception $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
}
