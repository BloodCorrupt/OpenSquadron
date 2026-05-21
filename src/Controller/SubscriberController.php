<?php

namespace App\Controller;

use App\Entity\Subscriber;
use App\Entity\WhatsAppConnection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SubscriberController extends AbstractController
{
    #[Route('/admin/subscribers', name: 'app_subscribers', methods: ['GET'])]
    public function index(Request $request, EntityManagerInterface $em): Response
    {
        $connections = $em->getRepository(WhatsAppConnection::class)->findBy([], ['id' => 'DESC']);
        
        $selectedConnectionId = $request->query->get('connectionId');
        $selectedConnection = null;
        if ($selectedConnectionId) {
            $selectedConnection = $em->getRepository(WhatsAppConnection::class)->find($selectedConnectionId);
        }
        if (!$selectedConnection && !empty($connections)) {
            $selectedConnection = $connections[0];
        }

        $subscribers = [];
        $search = trim($request->query->get('search', ''));
        $status = trim($request->query->get('status', 'all'));

        if ($selectedConnection) {
            $qb = $em->getRepository(Subscriber::class)->createQueryBuilder('s')
                ->where('s.whatsAppConnection = :connection')
                ->setParameter('connection', $selectedConnection);

            if ($search !== '') {
                $qb->andWhere('s.name LIKE :search OR s.phoneNumber LIKE :search')
                   ->setParameter('search', '%' . $search . '%');
            }

            if ($status !== 'all') {
                $qb->andWhere('s.status = :status')
                   ->setParameter('status', $status);
            }

            $qb->orderBy('s.id', 'DESC');
            $subscribers = $qb->getQuery()->getResult();
        }

        return $this->render('subscriber/index.html.twig', [
            'connections' => $connections,
            'connection' => $selectedConnection,
            'subscribers' => $subscribers,
            'search' => $search,
            'status' => $status,
        ]);
    }

    #[Route('/admin/subscribers/save', name: 'app_subscribers_save', methods: ['POST'])]
    public function save(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $id = $request->request->get('id');
        $phoneNumber = trim($request->request->get('phoneNumber', ''));
        $name = trim($request->request->get('name', ''));
        $status = trim($request->request->get('status', 'active'));
        $connectionId = $request->request->get('connectionId');

        if (!$connectionId) {
            return new JsonResponse(['success' => false, 'error' => 'No active WhatsApp connection selected.'], 400);
        }

        $connection = $em->getRepository(WhatsAppConnection::class)->find($connectionId);
        if (!$connection) {
            return new JsonResponse(['success' => false, 'error' => 'WhatsApp connection not found.'], 404);
        }

        if ($phoneNumber === '') {
            return new JsonResponse(['success' => false, 'error' => 'Phone number is required.'], 400);
        }

        // Clean phone number (keep only digits and optional + at the beginning)
        $cleanPhone = preg_replace('/[^\d+]/', '', $phoneNumber);

        if ($id) {
            $subscriber = $em->getRepository(Subscriber::class)->find($id);
            if (!$subscriber) {
                return new JsonResponse(['success' => false, 'error' => 'Subscriber not found.'], 404);
            }

            // Verify another subscriber doesn't exist with the same clean number on this connection
            $existing = $em->getRepository(Subscriber::class)->findOneBy([
                'phoneNumber' => $cleanPhone,
                'whatsAppConnection' => $connection
            ]);
            if ($existing && $existing->getId() !== $subscriber->getId()) {
                return new JsonResponse(['success' => false, 'error' => 'A subscriber with this phone number already exists for this connection.'], 400);
            }
        } else {
            // Check uniqueness on creation
            $existing = $em->getRepository(Subscriber::class)->findOneBy([
                'phoneNumber' => $cleanPhone,
                'whatsAppConnection' => $connection
            ]);
            if ($existing) {
                return new JsonResponse(['success' => false, 'error' => 'A subscriber with this phone number already exists for this connection.'], 400);
            }

            $subscriber = new Subscriber();
            $subscriber->setWhatsAppConnection($connection);
        }

        $subscriber->setPhoneNumber($cleanPhone);
        $subscriber->setName($name !== '' ? $name : null);
        $subscriber->setStatus($status);
        $subscriber->setUpdatedAt(new \DateTime('now', new \DateTimeZone('UTC')));

        $em->persist($subscriber);
        $em->flush();

        return new JsonResponse(['success' => true]);
    }

    #[Route('/admin/subscribers/toggle-status', name: 'app_subscribers_toggle_status', methods: ['POST'])]
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

    #[Route('/admin/subscribers/delete/{id}', name: 'app_subscribers_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
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
