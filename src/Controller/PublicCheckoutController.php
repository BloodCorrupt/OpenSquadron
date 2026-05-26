<?php

namespace App\Controller;

use App\Entity\EcomOrder;
use App\Entity\EcomProduct;
use App\Entity\FacebookConnection;
use App\Entity\InstagramConnection;
use App\Entity\WhatsAppConnection;
use App\Entity\EcomSetting;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Repository\EcomOrderRepository;

class PublicCheckoutController extends AbstractController
{
    #[Route('/c/{channel}/{connectionId}/{senderId}', name: 'app_public_checkout', methods: ['GET'])]
    public function index(
        string $channel,
        int $connectionId,
        string $senderId,
        EntityManagerInterface $em
    ): Response {
        $owner = null;

        if ($channel === 'whatsapp') {
            $conn = $em->getRepository(WhatsAppConnection::class)->find($connectionId);
            $owner = $conn ? $conn->getOwner() : null;
        } elseif ($channel === 'facebook') {
            $conn = $em->getRepository(FacebookConnection::class)->find($connectionId);
            $owner = $conn ? $conn->getOwner() : null;
        } elseif ($channel === 'instagram') {
            $conn = $em->getRepository(InstagramConnection::class)->find($connectionId);
            $owner = $conn ? $conn->getOwner() : null;
        }

        if (!$owner) {
            throw $this->createNotFoundException('Checkout link is invalid or expired.');
        }

        $products = $em->getRepository(EcomProduct::class)->findBy([
            'owner' => $owner,
            'status' => 'active'
        ], ['name' => 'ASC']);

        $setting = $em->getRepository(EcomSetting::class)->findOneBy(['owner' => $owner]);
        $checkoutEnabled = $setting ? $setting->isCheckoutEnabled() : true;
        $paymentInstructions = $setting ? $setting->getPaymentInstructions() : '';
        
        if (!$checkoutEnabled) {
            return new Response('Checkout is currently disabled by the store owner.', 403);
        }

        return $this->render('checkout/index.html.twig', [
            'products' => $products,
            'paymentInstructions' => $paymentInstructions,
            'channel' => $channel,
            'connectionId' => $connectionId,
            'senderId' => $senderId,
            'brandName' => 'Secure Checkout', // We can enhance this later
        ]);
    }

    #[Route('/c/api/submit', name: 'app_public_checkout_submit', methods: ['POST'])]
    public function submitOrder(Request $request, EntityManagerInterface $em, EcomOrderRepository $orderRepo): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!$data) {
            return $this->json(['error' => 'Invalid payload.'], 400);
        }

        $channel = $data['channel'] ?? '';
        $connectionId = $data['connectionId'] ?? null;
        $senderId = $data['senderId'] ?? '';
        
        $owner = null;

        if ($channel === 'whatsapp') {
            $conn = $em->getRepository(WhatsAppConnection::class)->find($connectionId);
            $owner = $conn ? $conn->getOwner() : null;
        } elseif ($channel === 'facebook') {
            $conn = $em->getRepository(FacebookConnection::class)->find($connectionId);
            $owner = $conn ? $conn->getOwner() : null;
        } elseif ($channel === 'instagram') {
            $conn = $em->getRepository(InstagramConnection::class)->find($connectionId);
            $owner = $conn ? $conn->getOwner() : null;
        }

        if (!$owner) {
            return $this->json(['error' => 'Invalid connection context.'], 403);
        }

        $customerName = trim($data['customerName'] ?? '');
        $customerContact = trim($data['customerContact'] ?? '');
        $shippingAddress = trim($data['shippingAddress'] ?? '');
        $items = $data['items'] ?? [];
        $totalAmount = $data['totalAmount'] ?? '0.00';
        $currency = $data['currency'] ?? 'USD';

        if ($customerName === '' || empty($items)) {
            return $this->json(['error' => 'Customer name and at least one item are required.'], 400);
        }

        $order = new EcomOrder();
        $order->setOwner($owner);
        $order->setOrderNumber($orderRepo->generateOrderNumber());
        $order->setCustomerName($customerName);
        $order->setCustomerContact($customerContact);
        $order->setChannel($channel . ' (ID: ' . $senderId . ')');
        $order->setItems($items);
        $order->setTotalAmount((string) $totalAmount);
        $order->setCurrency(strtoupper($currency));
        $order->setStatus('pending');
        $order->setShippingAddress($shippingAddress);

        $em->persist($order);
        $em->flush();

        return $this->json([
            'success' => true,
            'orderNumber' => $order->getOrderNumber()
        ]);
    }
}
