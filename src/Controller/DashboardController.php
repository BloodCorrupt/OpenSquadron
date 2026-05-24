<?php

namespace App\Controller;

use App\Entity\WhatsAppConnection;
use App\Entity\FacebookConnection;
use App\Entity\WhatsappBotFlow;
use App\Entity\FacebookBotFlow;
use App\Entity\Subscriber;
use App\Entity\Message;
use App\Entity\MessageTemplate;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Security\Voter\TeamPermissionVoter;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(TeamPermissionVoter::PERM_DASHBOARD_VIEW)]
class DashboardController extends AbstractController
{
    #[Route('/', name: 'app_dashboard')]
    public function index(EntityManagerInterface $em): Response
    {
        $waConnCount = $em->getRepository(WhatsAppConnection::class)->count([]);
        $fbConnCount = $em->getRepository(FacebookConnection::class)->count([]);
        
        $waFlowCount = $em->getRepository(WhatsappBotFlow::class)->count([]);
        $fbFlowCount = $em->getRepository(FacebookBotFlow::class)->count([]);
        
        $subTotalCount = $em->getRepository(Subscriber::class)->count([]);
        $subActiveCount = $em->getRepository(Subscriber::class)->count(['status' => 'active']);
        $subUnsubCount = $em->getRepository(Subscriber::class)->count(['status' => 'unsubscribed']);
        $subWhatsAppCount = $em->getRepository(Subscriber::class)->count(['channel' => 'whatsapp']);
        $subFacebookCount = $em->getRepository(Subscriber::class)->count(['channel' => 'facebook']);
        
        $templateCount = $em->getRepository(MessageTemplate::class)->count([]);
        
        $msgCount = 0;
        try {
            $msgCount = $em->getRepository(Message::class)->count([]);
        } catch (\Exception $e) {
        }

        $recentSubscribers = [];
        try {
            $recentSubscribers = $em->getRepository(Subscriber::class)->findBy([], ['id' => 'DESC'], 5);
        } catch (\Exception $e) {
        }

        return $this->render('dashboard/index.html.twig', [
            'wa_conn_count' => $waConnCount,
            'fb_conn_count' => $fbConnCount,
            'wa_flow_count' => $waFlowCount,
            'fb_flow_count' => $fbFlowCount,
            'sub_total_count' => $subTotalCount,
            'sub_active_count' => $subActiveCount,
            'sub_unsub_count' => $subUnsubCount,
            'sub_whatsapp_count' => $subWhatsAppCount,
            'sub_facebook_count' => $subFacebookCount,
            'template_count' => $templateCount,
            'msg_count' => $msgCount,
            'recent_subscribers' => $recentSubscribers,
        ]);
    }
}

