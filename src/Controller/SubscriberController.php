<?php

namespace App\Controller;

use App\Entity\Subscriber;
use App\Entity\WhatsAppConnection;
use App\Entity\FacebookConnection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Security\Voter\TeamPermissionVoter;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(TeamPermissionVoter::PERM_SUBSCRIBERS_VIEW)]
class SubscriberController extends AbstractController
{
    #[Route('/subscribers', name: 'app_subscribers', methods: ['GET'])]
    public function index(Request $request, EntityManagerInterface $em): Response
    {
        $whatsappConnections = $em->getRepository(WhatsAppConnection::class)->findBy([], ['id' => 'DESC']);
        $facebookConnections = $em->getRepository(FacebookConnection::class)->findBy([], ['id' => 'DESC']);
        $instagramConnections = $em->getRepository(\App\Entity\InstagramConnection::class)->findBy([], ['id' => 'DESC']);

        $channel = $request->query->get('channel');
        if (!$channel) {
            if (empty($whatsappConnections) && !empty($facebookConnections)) {
                $channel = 'facebook';
            } elseif (empty($whatsappConnections) && empty($facebookConnections) && !empty($instagramConnections)) {
                $channel = 'instagram';
            } else {
                $channel = 'whatsapp';
            }
        }

        $connections = ($channel === 'facebook') ? $facebookConnections : (($channel === 'instagram') ? $instagramConnections : $whatsappConnections);

        $selectedConnectionId = $request->query->get('connectionId');
        $selectedConnection = null;
        if ($selectedConnectionId) {
            if ($channel === 'facebook') {
                $selectedConnection = $em->getRepository(FacebookConnection::class)->find($selectedConnectionId);
            } elseif ($channel === 'instagram') {
                $selectedConnection = $em->getRepository(\App\Entity\InstagramConnection::class)->find($selectedConnectionId);
            } else {
                $selectedConnection = $em->getRepository(WhatsAppConnection::class)->find($selectedConnectionId);
            }
        }
        if (!$selectedConnection && !empty($connections)) {
            $selectedConnection = $connections[0];
        }

        $subscribers = [];
        $search = trim($request->query->get('search', ''));
        $status = trim($request->query->get('status', 'all'));

        if ($selectedConnection) {
            $qb = $em->getRepository(Subscriber::class)->createQueryBuilder('s');
            if ($channel === 'facebook') {
                $qb->where('s.facebookConnection = :connection')
                   ->setParameter('connection', $selectedConnection);

                if ($search !== '') {
                    $qb->andWhere('s.name LIKE :search OR s.psid LIKE :search')
                       ->setParameter('search', '%' . $search . '%');
                }
            } elseif ($channel === 'instagram') {
                $qb->where('s.instagramConnection = :connection')
                   ->setParameter('connection', $selectedConnection);

                if ($search !== '') {
                    $qb->andWhere('s.name LIKE :search OR s.psid LIKE :search')
                       ->setParameter('search', '%' . $search . '%');
                }
            } else {
                $qb->where('s.whatsAppConnection = :connection')
                   ->setParameter('connection', $selectedConnection);

                if ($search !== '') {
                    $qb->andWhere('s.name LIKE :search OR s.phoneNumber LIKE :search')
                       ->setParameter('search', '%' . $search . '%');
                }
            }

            if ($status !== 'all') {
                $qb->andWhere('s.status = :status')
                   ->setParameter('status', $status);
            }

            $qb->orderBy('s.id', 'DESC');
            $subscribers = $qb->getQuery()->getResult();
        }

        return $this->render('subscriber/index.html.twig', [
            'whatsappConnections' => $whatsappConnections,
            'facebookConnections' => $facebookConnections,
            'instagramConnections' => $instagramConnections,
            'connections' => $connections,
            'connection' => $selectedConnection,
            'channel' => $channel,
            'subscribers' => $subscribers,
            'search' => $search,
            'status' => $status,
        ]);
    }

    #[Route('/subscribers/save', name: 'app_subscribers_save', methods: ['POST'])]
    public function save(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $id = $request->request->get('id');
        $phoneNumber = trim($request->request->get('phoneNumber', ''));
        $psid = trim($request->request->get('psid', ''));
        $name = trim($request->request->get('name', ''));
        $status = trim($request->request->get('status', 'active'));
        $connectionId = $request->request->get('connectionId');
        $channel = trim($request->request->get('channel', 'whatsapp'));

        if (!$connectionId) {
            return new JsonResponse(['success' => false, 'error' => 'No active connection selected.'], 400);
        }

        if ($channel === 'facebook') {
            $connection = $em->getRepository(FacebookConnection::class)->find($connectionId);
            if (!$connection) {
                return new JsonResponse(['success' => false, 'error' => 'Facebook connection not found.'], 404);
            }

            if ($psid === '') {
                return new JsonResponse(['success' => false, 'error' => 'PSID is required.'], 400);
            }
        } elseif ($channel === 'instagram') {
            $connection = $em->getRepository(\App\Entity\InstagramConnection::class)->find($connectionId);
            if (!$connection) {
                return new JsonResponse(['success' => false, 'error' => 'Instagram connection not found.'], 404);
            }

            if ($psid === '') {
                return new JsonResponse(['success' => false, 'error' => 'IGSID is required.'], 400);
            }
        } else {
            $connection = $em->getRepository(WhatsAppConnection::class)->find($connectionId);
            if (!$connection) {
                return new JsonResponse(['success' => false, 'error' => 'WhatsApp connection not found.'], 404);
            }

            if ($phoneNumber === '') {
                return new JsonResponse(['success' => false, 'error' => 'Phone number is required.'], 400);
            }

            // Clean phone number (keep only digits and optional + at the beginning)
            $cleanPhone = preg_replace('/[^\d+]/', '', $phoneNumber);
        }

        if ($id) {
            $subscriber = $em->getRepository(Subscriber::class)->find($id);
            if (!$subscriber) {
                return new JsonResponse(['success' => false, 'error' => 'Subscriber not found.'], 404);
            }

            if ($channel === 'facebook') {
                $existing = $em->getRepository(Subscriber::class)->findOneBy([
                    'psid' => $psid,
                    'facebookConnection' => $connection
                ]);
            } elseif ($channel === 'instagram') {
                $existing = $em->getRepository(Subscriber::class)->findOneBy([
                    'psid' => $psid,
                    'instagramConnection' => $connection
                ]);
            } else {
                $existing = $em->getRepository(Subscriber::class)->findOneBy([
                    'phoneNumber' => $cleanPhone,
                    'whatsAppConnection' => $connection
                ]);
            }

            if ($existing && $existing->getId() !== $subscriber->getId()) {
                $errorMsg = ($channel === 'facebook')
                    ? 'A subscriber with this PSID already exists for this connection.'
                    : 'A subscriber with this phone number already exists for this connection.';
                return new JsonResponse(['success' => false, 'error' => $errorMsg], 400);
            }
        } else {
            // Check uniqueness on creation
            if ($channel === 'facebook') {
                $existing = $em->getRepository(Subscriber::class)->findOneBy([
                    'psid' => $psid,
                    'facebookConnection' => $connection
                ]);
            } elseif ($channel === 'instagram') {
                $existing = $em->getRepository(Subscriber::class)->findOneBy([
                    'psid' => $psid,
                    'instagramConnection' => $connection
                ]);
            } else {
                $existing = $em->getRepository(Subscriber::class)->findOneBy([
                    'phoneNumber' => $cleanPhone,
                    'whatsAppConnection' => $connection
                ]);
            }

            if ($existing) {
                $errorMsg = ($channel === 'facebook')
                    ? 'A subscriber with this PSID already exists for this connection.'
                    : 'A subscriber with this phone number already exists for this connection.';
                return new JsonResponse(['success' => false, 'error' => $errorMsg], 400);
            }

            $subscriber = new Subscriber();
            $subscriber->setChannel($channel);
            if ($channel === 'facebook') {
                $subscriber->setFacebookConnection($connection);
            } elseif ($channel === 'instagram') {
                $subscriber->setInstagramConnection($connection);
            } else {
                $subscriber->setWhatsAppConnection($connection);
            }
        }

        if ($channel === 'facebook' || $channel === 'instagram') {
            $subscriber->setPsid($psid);
        } else {
            $subscriber->setPhoneNumber($cleanPhone);
        }
        $subscriber->setName($name !== '' ? $name : null);
        $subscriber->setStatus($status);
        $subscriber->setUpdatedAt(new \DateTime('now', new \DateTimeZone('UTC')));

        $em->persist($subscriber);
        $em->flush();

        return new JsonResponse(['success' => true]);
    }

    #[Route('/subscribers/toggle-status', name: 'app_subscribers_toggle_status', methods: ['POST'])]
    public function toggleStatus(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $id = $request->request->get('id');
        $isActive = $request->request->get('isActive') === 'true';

        if (!$id) {
            return new JsonResponse(['success' => false, 'error' => 'Subscriber ID is required.'], 400);
        }

        $subscriber = $em->getRepository(Subscriber::class)->find($id);
        if (!$subscriber) {
            return new JsonResponse(['success' => false, 'error' => 'Subscriber not found.'], 404);
        }

        $subscriber->setStatus($isActive ? 'active' : 'unsubscribed');
        $subscriber->setUpdatedAt(new \DateTime('now', new \DateTimeZone('UTC')));
        $em->flush();

        return new JsonResponse(['success' => true]);
    }

    #[Route('/subscribers/delete/{id}', name: 'app_subscribers_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id, EntityManagerInterface $em): JsonResponse
    {
        $subscriber = $em->getRepository(Subscriber::class)->find($id);
        if (!$subscriber) {
            return new JsonResponse(['success' => false, 'error' => 'Subscriber not found.'], 404);
        }

        $em->remove($subscriber);
        $em->flush();

        return new JsonResponse(['success' => true]);
    }
}
