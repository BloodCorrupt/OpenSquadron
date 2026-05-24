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

        // Generate dynamic organic subscriber acquisition chart data
        $thirtyDaysAgo = new \DateTime('-30 days');
        $thirtyDaysAgo->setTime(0, 0, 0);

        try {
            $baseSubCount = (int) $em->createQuery(
                'SELECT COUNT(s.id) FROM App\Entity\Subscriber s WHERE s.createdAt < :date'
            )->setParameter('date', $thirtyDaysAgo)->getSingleScalarResult();

            $subDates = $em->createQuery(
                'SELECT s.createdAt FROM App\Entity\Subscriber s WHERE s.createdAt >= :date ORDER BY s.createdAt ASC'
            )->setParameter('date', $thirtyDaysAgo)->getResult();
        } catch (\Exception $e) {
            $baseSubCount = 0;
            $subDates = [];
        }

        $dailyCounts = array_fill(0, 31, 0);
        foreach ($subDates as $row) {
            /** @var \DateTimeInterface $date */
            $date = $row['createdAt'];
            $diff = $thirtyDaysAgo->diff($date)->days;
            if ($diff >= 0 && $diff <= 30) {
                $dailyCounts[$diff]++;
            }
        }

        $cumulative = $baseSubCount;
        $trendData = [];
        for ($i = 0; $i <= 30; $i++) {
            $cumulative += $dailyCounts[$i];
            
            // Only take 6 data points (0, 6, 12, 18, 24, 30)
            if ($i % 6 === 0) {
                $targetDate = clone $thirtyDaysAgo;
                $targetDate->modify("+$i days");
                $trendData[] = [
                    'date' => $targetDate->format('d M'),
                    'count' => $cumulative
                ];
            }
        }

        $chartData = $this->generateSvgChartData($trendData);

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
            'chart' => $chartData,
        ]);
    }

    private function generateSvgChartData(array $dataPoints, int $width = 500, int $height = 180, int $paddingX = 10, int $paddingY = 25): ?array
    {
        $count = count($dataPoints);
        if ($count < 2) return null;

        $maxVal = max(array_column($dataPoints, 'count'));
        $minVal = min(array_column($dataPoints, 'count'));
        $range = $maxVal - $minVal;
        if ($range == 0) $range = 1;

        $usableWidth = $width - ($paddingX * 2);
        $usableHeight = $height - ($paddingY * 2);

        $points = [];
        foreach ($dataPoints as $i => $dp) {
            $x = $paddingX + ($i / ($count - 1)) * $usableWidth;
            // Invert Y because SVG 0,0 is top-left
            $y = $height - $paddingY - ((($dp['count'] - $minVal) / $range) * $usableHeight);
            $points[] = ['x' => $x, 'y' => $y, 'label' => $dp['date'], 'val' => $dp['count']];
        }

        // Generate smooth path using Catmull-Rom to Cubic Bezier
        $path = "M " . $points[0]['x'] . "," . $points[0]['y'] . " ";
        
        for ($i = 0; $i < $count - 1; $i++) {
            $p0 = $i > 0 ? $points[$i - 1] : $points[$i];
            $p1 = $points[$i];
            $p2 = $points[$i + 1];
            $p3 = $i < $count - 2 ? $points[$i + 2] : $points[$i + 1];

            $tension = 0.2;
            $cp1x = $p1['x'] + ($p2['x'] - $p0['x']) * $tension;
            $cp1y = $p1['y'] + ($p2['y'] - $p0['y']) * $tension;
            
            $cp2x = $p2['x'] - ($p3['x'] - $p1['x']) * $tension;
            $cp2y = $p2['y'] - ($p3['y'] - $p1['y']) * $tension;

            $path .= "C " . round($cp1x, 2) . "," . round($cp1y, 2) . " " . 
                            round($cp2x, 2) . "," . round($cp2y, 2) . " " . 
                            round($p2['x'], 2) . "," . round($p2['y'], 2) . " ";
        }

        return [
            'path' => $path,
            'points' => $points,
            'fillPath' => $path . " L " . end($points)['x'] . "," . $height . " L " . $points[0]['x'] . "," . $height . " Z"
        ];
    }
}

