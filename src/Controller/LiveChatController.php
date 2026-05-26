<?php

namespace App\Controller;

use App\Entity\Message;
use App\Entity\Subscriber;
use App\Service\WhatsAppConnectionService;
use App\Service\FacebookService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Security\Voter\TeamPermissionVoter;

use App\Entity\WhatsAppConnection;
use App\Entity\FacebookConnection;
use App\Entity\InstagramConnection;

#[IsGranted(TeamPermissionVoter::PERM_SUBSCRIBERS_VIEW)]
class LiveChatController extends AbstractController
{
    #[Route('/inbox', name: 'app_inbox', methods: ['GET'])]
    public function index(EntityManagerInterface $em): Response
    {
        // Get all subscribers ordered by the most recently updated (newest message)
        $subscribers = $em->getRepository(Subscriber::class)->findBy([], ['updatedAt' => 'DESC']);
        $templates = $em->getRepository(\App\Entity\MessageTemplate::class)->findAll();
        $whatsappConnections = $em->getRepository(WhatsAppConnection::class)->findBy([], ['label' => 'ASC']);
        $facebookConnections = $em->getRepository(FacebookConnection::class)->findBy([], ['label' => 'ASC']);
        $instagramConnections = $em->getRepository(InstagramConnection::class)->findBy([], ['label' => 'ASC']);

        /** @var \App\Entity\Admin $currentUser */
        $currentUser = $this->getUser();
        $tenantOwner = $currentUser->getParent() ?: $currentUser;
        
        $operators = $em->getRepository(\App\Entity\Admin::class)->createQueryBuilder('a')
            ->where('a = :owner')
            ->orWhere('a.parent = :owner')
            ->setParameter('owner', $tenantOwner)
            ->orderBy('a.name', 'ASC')
            ->getQuery()
            ->getResult();

        $whatsappFlows = $em->getRepository(\App\Entity\WhatsappBotFlow::class)->findBy(['isActive' => true], ['name' => 'ASC']);
        $facebookFlows = $em->getRepository(\App\Entity\FacebookBotFlow::class)->findBy(['isActive' => true], ['name' => 'ASC']);
        $instagramFlows = $em->getRepository(\App\Entity\InstagramBotFlow::class)->findBy(['isActive' => true], ['name' => 'ASC']);

        return $this->render('chat/inbox.html.twig', [
            'subscribers' => $subscribers,
            'activeSubscriber' => null,
            'messages' => [],
            'templates' => $templates,
            'whatsappConnections' => $whatsappConnections,
            'facebookConnections' => $facebookConnections,
            'instagramConnections' => $instagramConnections,
            'operators' => $operators,
            'whatsappFlows' => $whatsappFlows,
            'facebookFlows' => $facebookFlows,
            'instagramFlows' => $instagramFlows,
            'chatWindow' => [
                'isOpen' => false,
                'expiresAt' => null,
                'remainingSeconds' => 0
            ]
        ]);
    }

    #[Route('/inbox/{id}', name: 'app_inbox_chat', methods: ['GET'])]
    public function chat(Subscriber $subscriber, EntityManagerInterface $em): Response
    {
        $subscribers = $em->getRepository(Subscriber::class)->findBy([], ['updatedAt' => 'DESC']);
        $messages = $em->getRepository(Message::class)->findBy(['subscriber' => $subscriber], ['timestamp' => 'ASC']);
        $templates = $em->getRepository(\App\Entity\MessageTemplate::class)->findAll();
        $whatsappConnections = $em->getRepository(WhatsAppConnection::class)->findBy([], ['label' => 'ASC']);
        $facebookConnections = $em->getRepository(FacebookConnection::class)->findBy([], ['label' => 'ASC']);
        $instagramConnections = $em->getRepository(InstagramConnection::class)->findBy([], ['label' => 'ASC']);

        /** @var \App\Entity\Admin $currentUser */
        $currentUser = $this->getUser();
        $tenantOwner = $currentUser->getParent() ?: $currentUser;
        
        $operators = $em->getRepository(\App\Entity\Admin::class)->createQueryBuilder('a')
            ->where('a = :owner')
            ->orWhere('a.parent = :owner')
            ->setParameter('owner', $tenantOwner)
            ->orderBy('a.name', 'ASC')
            ->getQuery()
            ->getResult();

        $whatsappFlows = $em->getRepository(\App\Entity\WhatsappBotFlow::class)->findBy(['isActive' => true], ['name' => 'ASC']);
        $facebookFlows = $em->getRepository(\App\Entity\FacebookBotFlow::class)->findBy(['isActive' => true], ['name' => 'ASC']);
        $instagramFlows = $em->getRepository(\App\Entity\InstagramBotFlow::class)->findBy(['isActive' => true], ['name' => 'ASC']);

        $chatWindow = $this->getChatWindowStatus($subscriber, $em);

        return $this->render('chat/inbox.html.twig', [
            'subscribers' => $subscribers,
            'activeSubscriber' => $subscriber,
            'messages' => $messages,
            'templates' => $templates,
            'whatsappConnections' => $whatsappConnections,
            'facebookConnections' => $facebookConnections,
            'instagramConnections' => $instagramConnections,
            'operators' => $operators,
            'whatsappFlows' => $whatsappFlows,
            'facebookFlows' => $facebookFlows,
            'instagramFlows' => $instagramFlows,
            'chatWindow' => $chatWindow,
            'serverTimestamp' => time()
        ]);
    }

    #[Route('/inbox/api/subscribers', name: 'app_inbox_api_subscribers', methods: ['GET'])]
    public function getSubscribers(EntityManagerInterface $em): JsonResponse
    {
        $subscribers = $em->getRepository(Subscriber::class)->findBy([], ['updatedAt' => 'DESC']);
        $data = [];
        foreach ($subscribers as $sub) {
            $lastMessage = null;
            $messages = $em->getRepository(Message::class)->findBy(['subscriber' => $sub], ['timestamp' => 'DESC'], 1);
            if (!empty($messages)) {
                $msg = $messages[0];
                $lastMessage = [
                    'content' => $msg->getContent(),
                    'type' => $msg->getType(),
                    'direction' => $msg->getDirection(),
                    'timestamp' => $msg->getTimestamp()->format('H:i'),
                ];
            }
            
            $data[] = [
                'id' => $sub->getId(),
                'name' => $sub->getName() ?: ($sub->getPhoneNumber() ?: $sub->getPsid()),
                'phoneNumber' => $sub->getPhoneNumber() ?: $sub->getPsid(),
                'channel' => $sub->getChannel() ?? 'whatsapp',
                'connectionId' => match($sub->getChannel()) {
                    'facebook' => $sub->getFacebookConnection() ? 'facebook-' . $sub->getFacebookConnection()->getId() : null,
                    'Instagram' => $sub->getInstagramConnection() ? 'instagram-' . $sub->getInstagramConnection()->getId() : null,
                    default => $sub->getWhatsAppConnection() ? 'whatsapp-' . $sub->getWhatsAppConnection()->getId() : null,
                },
                'botPaused' => $sub->isBotPaused(),
                'lastMessage' => $lastMessage,
            ];
        }
        
        return new JsonResponse($data);
    }

    #[Route('/inbox/api/subscriber/{id}/toggle-bot-pause', name: 'app_inbox_toggle_bot_pause', methods: ['POST'])]
    public function toggleBotPause(Subscriber $subscriber, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $this->denyAccessUnlessGranted(TeamPermissionVoter::PERM_SUBSCRIBERS_MANAGE);
        
        $data = json_decode($request->getContent(), true);
        if ($data && isset($data['botPaused'])) {
            $botPaused = filter_var($data['botPaused'], FILTER_VALIDATE_BOOLEAN);
        } else {
            $botPaused = !$subscriber->isBotPaused();
        }
        
        $subscriber->setBotPaused($botPaused);
        $em->flush();
        
        return new JsonResponse(['success' => true, 'botPaused' => $subscriber->isBotPaused()]);
    }

    #[Route('/inbox/api/messages/{id}', name: 'app_inbox_api_messages', methods: ['GET'])]
    public function getMessages(Subscriber $subscriber, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $afterId = $request->query->getInt('after_id', 0);
        
        $qb = $em->createQueryBuilder()
            ->select('m')
            ->from(Message::class, 'm')
            ->where('m.subscriber = :subscriber')
            ->setParameter('subscriber', $subscriber)
            ->orderBy('m.id', 'ASC');
            
        if ($afterId > 0) {
            $qb->andWhere('m.id > :afterId')
               ->setParameter('afterId', $afterId);
        }
        
        $messages = $qb->getQuery()->getResult();
        
        $data = [];
        foreach ($messages as $msg) {
            $resMediaUrl = $msg->getMediaUrl();
            if ($resMediaUrl && !str_starts_with($resMediaUrl, 'http://') && !str_starts_with($resMediaUrl, 'https://')) {
                $resMediaUrl = '/' . $resMediaUrl;
            }
            $data[] = [
                'id' => $msg->getId(),
                'type' => $msg->getType(),
                'content' => $msg->getContent(),
                'mediaUrl' => $resMediaUrl,
                'timestamp' => $msg->getTimestamp()->format('Y-m-d H:i:s'),
                'timeOnly' => $msg->getTimestamp()->format('H:i'),
                'direction' => $msg->getDirection()
            ];
        }
        
        return new JsonResponse($data);
    }

    #[Route('/inbox/api/send', name: 'app_inbox_send', methods: ['POST'])]
    public function send(
        Request $request,
        EntityManagerInterface $em,
        WhatsAppConnectionService $whatsappService,
        FacebookService $facebookService,
        \App\Service\InstagramService $instagramService,
        \App\Service\R2SettingsService $r2SettingsService
    ): JsonResponse {
        $this->denyAccessUnlessGranted(TeamPermissionVoter::PERM_SUBSCRIBERS_MANAGE);
        $subscriberId = $request->request->get('subscriber_id');
        $content = $request->request->get('content', '');
        $file = $request->files->get('media');
        $mediaUrl = $request->request->get('media_url');
        $mediaType = $request->request->get('media_type', 'text');

        if (!$subscriberId || (empty(trim($content)) && !$file && !$mediaUrl)) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid data'], 400);
        }

        $subscriber = $em->getRepository(Subscriber::class)->find($subscriberId);
        if (!$subscriber) {
            return new JsonResponse(['success' => false, 'error' => 'Subscriber not found'], 404);
        }

        $channel = $subscriber->getChannel() ?? 'whatsapp';

        // Enforce 24-hour customer service window (WhatsApp only)
        if ($channel === 'whatsapp') {
            $chatWindow = $this->getChatWindowStatus($subscriber, $em);
            if (!$chatWindow['isOpen']) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'The 24-hour customer service window has expired. You can only respond using approved message templates.'
                ], 403);
            }
        }

        try {
            $msg = new Message();
            $msg->setSubscriber($subscriber);
            $msg->setDirection('outbound');
            $msg->setStatus('sent');

            $metaMessageId = null;

            // Resolve whether connection owner has R2 configured
            $connection = null;
            if ($channel === 'facebook') {
                $connection = $subscriber->getFacebookConnection();
            } elseif ($channel === 'Instagram') {
                $connection = $subscriber->getInstagramConnection();
            } else {
                $connection = $subscriber->getWhatsAppConnection();
            }
            $owner = $connection ? $connection->getOwner() : null;

            $r2Settings = null;
            if ($owner) {
                $r2Settings = $r2SettingsService->getActiveSettings($owner);
            }
            $isR2Configured = $r2SettingsService->isComplete($r2Settings);

            if ($file && !$isR2Configured) {
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
                    
                    $localMediaUrl = 'uploads/whatsapp_media/' . $filename;
                    $absoluteUrl = $request->getSchemeAndHttpHost() . '/' . $localMediaUrl;
                    
                    $msg->setType($type);
                    $msg->setMediaUrl($localMediaUrl);
                    $msg->setContent($content);
                    
                    if ($channel === 'facebook') {
                        $response = $facebookService->sendMediaMessage($subscriber->getPsid(), $type, $absoluteUrl, $subscriber->getFacebookConnection());
                        $metaMessageId = $response['message_id'] ?? null;
                        if (!empty(trim($content))) {
                            $facebookService->sendMessage($subscriber->getPsid(), $content, $subscriber->getFacebookConnection());
                        }
                    } elseif ($channel === 'Instagram') {
                        $response = $instagramService->sendMediaMessage($subscriber->getPsid(), $type, $absoluteUrl, $subscriber->getInstagramConnection());
                        $metaMessageId = $response['message_id'] ?? null;
                        if (!empty(trim($content))) {
                            $instagramService->sendMessage($subscriber->getPsid(), $content, $subscriber->getInstagramConnection());
                        }
                    } else {
                        $response = $whatsappService->sendMediaMessage($subscriber->getPhoneNumber(), $type, $absoluteUrl, $subscriber->getWhatsAppConnection());
                        $metaMessageId = $response['messages'][0]['id'] ?? null;
                        if (!empty(trim($content))) {
                            $whatsappService->sendMessage($subscriber->getPhoneNumber(), $content, $subscriber->getWhatsAppConnection());
                        }
                    }
                }
            } elseif ($mediaUrl && $mediaType !== 'text') {
                $msg->setType($mediaType);
                $msg->setMediaUrl($mediaUrl);
                $msg->setContent($content);
                
                if ($channel === 'facebook') {
                    $response = $facebookService->sendMediaMessage($subscriber->getPsid(), $mediaType, $mediaUrl, $subscriber->getFacebookConnection());
                    $metaMessageId = $response['message_id'] ?? null;
                    if (!empty(trim($content))) {
                        $facebookService->sendMessage($subscriber->getPsid(), $content, $subscriber->getFacebookConnection());
                    }
                } elseif ($channel === 'Instagram') {
                    $response = $instagramService->sendMediaMessage($subscriber->getPsid(), $mediaType, $mediaUrl, $subscriber->getInstagramConnection());
                    $metaMessageId = $response['message_id'] ?? null;
                    if (!empty(trim($content))) {
                        $instagramService->sendMessage($subscriber->getPsid(), $content, $subscriber->getInstagramConnection());
                    }
                } else {
                    $response = $whatsappService->sendMediaMessage($subscriber->getPhoneNumber(), $mediaType, $mediaUrl, $subscriber->getWhatsAppConnection());
                    $metaMessageId = $response['messages'][0]['id'] ?? null;
                    if (!empty(trim($content))) {
                        $whatsappService->sendMessage($subscriber->getPhoneNumber(), $content, $subscriber->getWhatsAppConnection());
                    }
                }
            } else {
                // Normal text message
                if ($channel === 'facebook') {
                    $response = $facebookService->sendMessage($subscriber->getPsid(), $content, $subscriber->getFacebookConnection());
                    $metaMessageId = $response['message_id'] ?? null;
                } elseif ($channel === 'Instagram' && $instagramService) {
                    $response = $instagramService->sendMessage($subscriber->getPsid(), $content, $subscriber->getInstagramConnection());
                    $metaMessageId = $response['message_id'] ?? null;
                } else {
                    $response = $whatsappService->sendMessage($subscriber->getPhoneNumber(), $content, $subscriber->getWhatsAppConnection());
                    $metaMessageId = $response['messages'][0]['id'] ?? null;
                }
                $msg->setType('text');
                $msg->setContent($content);
            }

            $msg->setMetaMessageId($metaMessageId);
            $em->persist($msg);

            // Update subscriber
            $subscriber->setUpdatedAt(new \DateTime('now', new \DateTimeZone('UTC')));
            
            $em->flush();

            $resMediaUrl = $msg->getMediaUrl();
            if ($resMediaUrl && !str_starts_with($resMediaUrl, 'http://') && !str_starts_with($resMediaUrl, 'https://')) {
                $resMediaUrl = '/' . $resMediaUrl;
            }

            return new JsonResponse([
                'success' => true, 
                'message' => [
                    'id' => $msg->getId(),
                    'type' => $msg->getType(),
                    'content' => $msg->getContent(),
                    'mediaUrl' => $resMediaUrl,
                    'timestamp' => $msg->getTimestamp()->format('Y-m-d H:i:s'),
                    'direction' => 'outbound'
                ]
            ]);

        } catch (\Exception $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[Route('/inbox/api/send-template', name: 'app_inbox_send_template', methods: ['POST'])]
    public function sendTemplate(Request $request, EntityManagerInterface $em, WhatsAppConnectionService $whatsappService): JsonResponse
    {
        $this->denyAccessUnlessGranted(TeamPermissionVoter::PERM_SUBSCRIBERS_MANAGE);
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
            $subscriber->setUpdatedAt(new \DateTime('now', new \DateTimeZone('UTC')));
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

    #[Route('/inbox/api/subscriber/{id}/details', name: 'app_inbox_api_subscriber_details', methods: ['GET'])]
    public function details(Subscriber $subscriber, EntityManagerInterface $em): JsonResponse
    {
        $chatWindow = $this->getChatWindowStatus($subscriber, $em);
        return new JsonResponse([
            'id' => $subscriber->getId(),
            'name' => $subscriber->getName(),
            'phoneNumber' => $subscriber->getPhoneNumber(),
            'psid' => $subscriber->getPsid(),
            'assignedOperatorId' => $subscriber->getAssignedOperator() ? $subscriber->getAssignedOperator()->getId() : null,
            'tags' => $subscriber->getTags(),
            'assignedFlowId' => match($subscriber->getChannel()) {
                'facebook' => $subscriber->getAssignedFacebookFlow() ? $subscriber->getAssignedFacebookFlow()->getId() : null,
                'Instagram' => $subscriber->getAssignedInstagramFlow() ? $subscriber->getAssignedInstagramFlow()->getId() : null,
                default => $subscriber->getAssignedWhatsappFlow() ? $subscriber->getAssignedWhatsappFlow()->getId() : null,
            },
            'customAttributes' => $subscriber->getCustomAttributes(),
            'notes' => $subscriber->getNotes(),
            'channel' => $subscriber->getChannel() ?? 'whatsapp',
            'chatWindow' => $chatWindow,
            'serverTimestamp' => time()
        ]);
    }

    #[Route('/inbox/api/subscriber/{id}/assign-operator', name: 'app_inbox_api_assign_operator', methods: ['POST'])]
    public function assignOperator(Subscriber $subscriber, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $this->denyAccessUnlessGranted(TeamPermissionVoter::PERM_SUBSCRIBERS_MANAGE);
        $operatorId = $request->request->get('operator_id');
        if (empty($operatorId)) {
            $subscriber->setAssignedOperator(null);
        } else {
            $operator = $em->getRepository(\App\Entity\Admin::class)->find($operatorId);
            if (!$operator) {
                return new JsonResponse(['success' => false, 'error' => 'Operator not found'], 404);
            }
            
            // Security check: Operator must belong to the same tenant workspace
            /** @var \App\Entity\Admin $currentUser */
            $currentUser = $this->getUser();
            $tenantOwner = $currentUser->getParent() ?: $currentUser;
            $opOwner = $operator->getParent() ?: $operator;
            
            if ($opOwner->getId() !== $tenantOwner->getId()) {
                return new JsonResponse(['success' => false, 'error' => 'Access denied'], 403);
            }
            
            $subscriber->setAssignedOperator($operator);
        }
        
        $em->flush();
        return new JsonResponse(['success' => true]);
    }

    #[Route('/inbox/api/subscriber/{id}/tags', name: 'app_inbox_api_tags', methods: ['POST'])]
    public function updateTags(Subscriber $subscriber, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $this->denyAccessUnlessGranted(TeamPermissionVoter::PERM_SUBSCRIBERS_MANAGE);
        $tagsData = $request->request->all('tags') ?: [];
        $subscriber->setTags(array_values(array_unique(array_filter(array_map('trim', $tagsData)))));
        $em->flush();
        return new JsonResponse(['success' => true]);
    }

    #[Route('/inbox/api/subscriber/{id}/assign-flow', name: 'app_inbox_api_assign_flow', methods: ['POST'])]
    public function assignFlow(Subscriber $subscriber, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $this->denyAccessUnlessGranted(TeamPermissionVoter::PERM_SUBSCRIBERS_MANAGE);
        $flowId = $request->request->get('flow_id');
        if (empty($flowId)) {
            $subscriber->setAssignedWhatsappFlow(null);
            $subscriber->setAssignedFacebookFlow(null);
        } else {
            if ($subscriber->getChannel() === 'facebook') {
                $flow = $em->getRepository(\App\Entity\FacebookBotFlow::class)->find($flowId);
                if (!$flow) {
                    return new JsonResponse(['success' => false, 'error' => 'Flow not found'], 404);
                }
                $subscriber->setAssignedFacebookFlow($flow);
                $subscriber->setAssignedWhatsappFlow(null);
            } else {
                $flow = $em->getRepository(\App\Entity\WhatsappBotFlow::class)->find($flowId);
                if (!$flow) {
                    return new JsonResponse(['success' => false, 'error' => 'Flow not found'], 404);
                }
                $subscriber->setAssignedWhatsappFlow($flow);
                $subscriber->setAssignedFacebookFlow(null);
            }
        }
        
        $em->flush();
        return new JsonResponse(['success' => true]);
    }

    #[Route('/inbox/api/subscriber/{id}/attribute', name: 'app_inbox_api_attribute', methods: ['POST'])]
    public function updateAttribute(Subscriber $subscriber, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $this->denyAccessUnlessGranted(TeamPermissionVoter::PERM_SUBSCRIBERS_MANAGE);
        $key = trim((string)$request->request->get('key'));
        $value = trim((string)$request->request->get('value'));
        $delete = $request->request->getBoolean('delete', false);
        
        if (empty($key)) {
            return new JsonResponse(['success' => false, 'error' => 'Key is required'], 400);
        }
        
        $attributes = $subscriber->getCustomAttributes();
        if ($delete) {
            unset($attributes[$key]);
        } else {
            $attributes[$key] = $value;
        }
        
        $subscriber->setCustomAttributes($attributes);
        $em->flush();
        return new JsonResponse(['success' => true]);
    }

    #[Route('/inbox/api/subscriber/{id}/notes', name: 'app_inbox_api_notes', methods: ['POST'])]
    public function addNote(Subscriber $subscriber, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $this->denyAccessUnlessGranted(TeamPermissionVoter::PERM_SUBSCRIBERS_MANAGE);
        $text = trim((string)$request->request->get('text'));
        if (empty($text)) {
            return new JsonResponse(['success' => false, 'error' => 'Note text is required'], 400);
        }
        
        /** @var \App\Entity\Admin $currentUser */
        $currentUser = $this->getUser();
        $notes = $subscriber->getNotes();
        $notes[] = [
            'text' => $text,
            'createdAt' => (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
            'operatorName' => $currentUser->getName() ?: $currentUser->getEmail()
        ];
        
        $subscriber->setNotes($notes);
        $em->flush();
        return new JsonResponse(['success' => true, 'notes' => $notes]);
    }

    #[Route('/inbox/api/subscriber/{id}/products', name: 'app_inbox_api_subscriber_products', methods: ['GET'])]
    public function getSubscriberProducts(Subscriber $subscriber, EntityManagerInterface $em): JsonResponse
    {
        $channel = strtolower(trim($subscriber->getChannel() ?? ''));
        $connectionId = null;
        $owner = null;

        if ($channel === 'whatsapp' || empty($channel)) {
            $conn = $subscriber->getWhatsAppConnection();
            if ($conn) { $connectionId = $conn->getId(); $owner = $conn->getOwner(); $channel = 'whatsapp'; }
        }
        
        if (!$connectionId && ($channel === 'facebook' || empty($channel))) {
            $conn = $subscriber->getFacebookConnection();
            if ($conn) { $connectionId = $conn->getId(); $owner = $conn->getOwner(); $channel = 'facebook'; }
        }
        
        if (!$connectionId && ($channel === 'instagram' || empty($channel))) {
            $conn = $subscriber->getInstagramConnection();
            if ($conn) { $connectionId = $conn->getId(); $owner = $conn->getOwner(); $channel = 'instagram'; }
        }

        if (!$owner || !$connectionId) {
            return new JsonResponse(['success' => false, 'error' => 'No active connection found.'], 404);
        }

        $products = $em->getRepository(\App\Entity\EcomProduct::class)->findBy([
            'owner' => $owner,
            'status' => 'active'
        ], ['name' => 'ASC']);

        $setting = $em->getRepository(\App\Entity\EcomSetting::class)->findOneBy(['owner' => $owner]);
        $checkoutEnabled = $setting ? $setting->isCheckoutEnabled() : true;
        $globalExternalUrl = $setting ? $setting->getGlobalExternalUrl() : null;

        $baseUrl = $this->generateUrl('app_public_checkout', [
            'channel' => $channel,
            'connectionId' => $connectionId,
            'senderId' => $subscriber->getPsid() ?: $subscriber->getPhoneNumber()
        ], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL);

        return new JsonResponse([
            'success' => true,
            'baseUrl' => $baseUrl,
            'checkoutEnabled' => $checkoutEnabled,
            'globalExternalUrl' => $globalExternalUrl,
            'products' => array_map(fn($p) => [
                'id' => $p->getId(),
                'name' => $p->getName(),
                'price' => $p->getPrice(),
                'currency' => $p->getCurrency(),
                'imageUrl' => $p->getImageUrl(),
                'externalUrl' => $p->getExternalUrl(),
                'galleryUrls' => $p->getGalleryUrls() ?: []
            ], $products)
        ]);
    }

    private function getChatWindowStatus(Subscriber $subscriber, EntityManagerInterface $em): array
    {
        // Facebook Messenger uses the HUMAN_AGENT tag which allows a 7-day window.
        // For beta, we skip the lockdown entirely for Facebook subscribers.
        $channel = $subscriber->getChannel() ?? 'whatsapp';
        if ($channel === 'facebook' || $channel === 'Instagram') {
            return [
                'isOpen' => true,
                'expiresAt' => null,
                'expiresAtTimestamp' => null,
                'remainingSeconds' => 0,
                'noWindow' => true
            ];
        }

        $lastInboundMessage = $em->getRepository(Message::class)->createQueryBuilder('m')
            ->where('m.subscriber = :subscriber')
            ->andWhere('m.direction = :direction')
            ->setParameter('subscriber', $subscriber)
            ->setParameter('direction', 'inbound')
            ->orderBy('m.timestamp', 'DESC')
            ->addOrderBy('m.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$lastInboundMessage) {
            return [
                'isOpen' => false,
                'expiresAt' => null,
                'expiresAtTimestamp' => null,
                'remainingSeconds' => 0
            ];
        }

        // Format to timezone-naive representation and parse explicitly in UTC
        $rawTimestamp = $lastInboundMessage->getTimestamp();
        $timestamp = new \DateTime($rawTimestamp->format('Y-m-d H:i:s'), new \DateTimeZone('UTC'));

        $expiresAt = (clone $timestamp)->modify('+24 hours');
        $now = new \DateTime('now', new \DateTimeZone('UTC'));

        $isOpen = $now < $expiresAt;
        $remainingSeconds = $isOpen ? ($expiresAt->getTimestamp() - $now->getTimestamp()) : 0;

        return [
            'isOpen' => $isOpen,
            'expiresAt' => $expiresAt->format('Y-m-d H:i:s'),
            'expiresAtTimestamp' => $expiresAt->getTimestamp(),
            'remainingSeconds' => $remainingSeconds
        ];
    }


}
