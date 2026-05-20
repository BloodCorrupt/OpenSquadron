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
        $templates = $em->getRepository(\App\Entity\MessageTemplate::class)->findAll();

        return $this->render('chat/inbox.html.twig', [
            'subscribers' => $subscribers,
            'activeSubscriber' => null,
            'messages' => [],
            'templates' => $templates
        ]);
    }

    #[Route('/admin/inbox/{id}', name: 'app_inbox_chat', methods: ['GET'])]
    public function chat(Subscriber $subscriber, EntityManagerInterface $em): Response
    {
        $subscribers = $em->getRepository(Subscriber::class)->findBy([], ['updatedAt' => 'DESC']);
        $messages = $em->getRepository(Message::class)->findBy(['subscriber' => $subscriber], ['timestamp' => 'ASC']);
        $templates = $em->getRepository(\App\Entity\MessageTemplate::class)->findAll();

        return $this->render('chat/inbox.html.twig', [
            'subscribers' => $subscribers,
            'activeSubscriber' => $subscriber,
            'messages' => $messages,
            'templates' => $templates
        ]);
    }

    #[Route('/admin/inbox/api/send', name: 'app_inbox_send', methods: ['POST'])]
    public function send(Request $request, EntityManagerInterface $em, WhatsAppConnectionService $whatsappService): JsonResponse
    {
        $subscriberId = $request->request->get('subscriber_id');
        $content = $request->request->get('content', '');
        $file = $request->files->get('media');

        if (!$subscriberId || (empty(trim($content)) && !$file)) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid data'], 400);
        }

        $subscriber = $em->getRepository(Subscriber::class)->find($subscriberId);
        if (!$subscriber) {
            return new JsonResponse(['success' => false, 'error' => 'Subscriber not found'], 404);
        }

        try {
            $msg = new Message();
            $msg->setSubscriber($subscriber);
            $msg->setDirection('outbound');
            $msg->setStatus('sent');

            $metaMessageId = null;

            if ($file) {
                // Use native PHP finfo to detect MIME (no symfony/mime needed)
                $finfo = new \finfo(FILEINFO_MIME_TYPE);
                $mimeType = $finfo->file($file->getPathname());
                $type = 'text';
                
                $extensionMap = [
                    'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif',
                    'image/webp' => 'webp', 'audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a',
                    'audio/ogg' => 'ogg', 'audio/wav' => 'wav', 'audio/aac' => 'aac',
                ];
                $ext = $extensionMap[$mimeType] ?? pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION) ?: 'bin';
                
                if (str_starts_with($mimeType, 'image/')) {
                    $type = 'image';
                } elseif (str_starts_with($mimeType, 'audio/')) {
                    $type = 'audio';
                }
                
                if ($type !== 'text') {
                    $uploadDir = $this->getParameter('kernel.project_dir') . '/public/uploads/whatsapp_media';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }
                    
                    $filename = uniqid('out_') . '.' . $ext;
                    $file->move($uploadDir, $filename);
                    
                    $mediaUrl = 'uploads/whatsapp_media/' . $filename;
                    $absoluteUrl = $request->getSchemeAndHttpHost() . '/' . $mediaUrl;
                    
                    $msg->setType($type);
                    $msg->setMediaUrl($mediaUrl);
                    $msg->setContent($content);
                    
                    $response = $whatsappService->sendMediaMessage($subscriber->getPhoneNumber(), $type, $absoluteUrl, $subscriber->getWhatsAppConnection());
                    $metaMessageId = $response['messages'][0]['id'] ?? null;
                }
            } else {
                // Normal text message
                $response = $whatsappService->sendMessage($subscriber->getPhoneNumber(), $content, $subscriber->getWhatsAppConnection());
                $metaMessageId = $response['messages'][0]['id'] ?? null;
                $msg->setType('text');
                $msg->setContent($content);
            }

            $msg->setMetaMessageId($metaMessageId);
            $em->persist($msg);

            // Update subscriber
            $subscriber->setUpdatedAt(new \DateTime());
            
            $em->flush();

            return new JsonResponse([
                'success' => true, 
                'message' => [
                    'id' => $msg->getId(),
                    'type' => $msg->getType(),
                    'content' => $msg->getContent(),
                    'mediaUrl' => $msg->getMediaUrl() ? '/' . $msg->getMediaUrl() : null,
                    'timestamp' => $msg->getTimestamp()->format('Y-m-d H:i:s'),
                    'direction' => 'outbound'
                ]
            ]);

        } catch (\Exception $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[Route('/admin/inbox/api/send-template', name: 'app_inbox_send_template', methods: ['POST'])]
    public function sendTemplate(Request $request, EntityManagerInterface $em, WhatsAppConnectionService $whatsappService): JsonResponse
    {
        $subscriberId = $request->request->get('subscriber_id');
        $templateName = $request->request->get('template_name');
        $languageCode = $request->request->get('language_code');

        if (!$subscriberId || !$templateName || !$languageCode) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid data'], 400);
        }

        $subscriber = $em->getRepository(Subscriber::class)->find($subscriberId);
        if (!$subscriber) {
            return new JsonResponse(['success' => false, 'error' => 'Subscriber not found'], 404);
        }

        try {
            $response = $whatsappService->sendTemplateMessage($subscriber->getPhoneNumber(), $templateName, $languageCode, $subscriber->getWhatsAppConnection());

            $msg = new Message();
            $msg->setSubscriber($subscriber);
            $msg->setDirection('outbound');
            $msg->setType('template');
            $msg->setContent("[Template: $templateName]");
            $msg->setMetaMessageId($response['messages'][0]['id'] ?? null);
            $msg->setStatus('sent');
            
            $em->persist($msg);
            $subscriber->setUpdatedAt(new \DateTime());
            $em->flush();

            return new JsonResponse([
                'success' => true, 
                'message' => [
                    'id' => $msg->getId(),
                    'type' => 'template',
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
