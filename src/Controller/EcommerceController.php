<?php

namespace App\Controller;

use App\Entity\Admin;
use App\Entity\EcomProduct;
use App\Entity\EcomOrder;
use App\Entity\FacebookConnection;
use App\Entity\InstagramConnection;
use App\Entity\EcomSetting;
use App\Repository\EcomProductRepository;
use App\Repository\EcomOrderRepository;
use App\Repository\EcomSettingRepository;
use App\Service\FacebookService;
use App\Service\InstagramService;
use App\Service\SubscriptionUsageService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Security\Voter\TeamPermissionVoter;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(TeamPermissionVoter::PERM_ECOMMERCE_MANAGE)]
class EcommerceController extends AbstractController
{
    public function __construct(
        private SubscriptionUsageService $usageService,
        private FacebookService $facebookService,
        private InstagramService $instagramService,
    ) {}

    // ───────────────────────── Main Page ─────────────────────────

    #[Route('/ecommerce', name: 'app_ecommerce')]
    public function index(EntityManagerInterface $em): Response
    {
        /** @var Admin $user */
        $user = $this->getUser();
        if (!$this->usageService->hasModuleAccess($user, 'ecommerce')) {
            $this->addFlash('error', 'Your subscription plan does not include access to the eCommerce Hub.');
            return $this->redirectToRoute('app_dashboard');
        }

        $fbConnections = $em->getRepository(FacebookConnection::class)->findBy([], ['id' => 'DESC']);
        $igConnections = $em->getRepository(InstagramConnection::class)->findBy([], ['id' => 'DESC']);

        return $this->render('ecommerce/index.html.twig', [
            'fbConnections' => $fbConnections,
            'igConnections' => $igConnections,
        ]);
    }

    // ───────────────────────── Products API ─────────────────────────

    #[Route('/ecommerce/products', name: 'app_ecommerce_products', methods: ['GET'])]
    public function listProducts(EcomProductRepository $repo): JsonResponse
    {
        $products = $repo->findBy([], ['id' => 'DESC']);
        return $this->json(['products' => array_map(fn(EcomProduct $p) => $p->toArray(), $products)]);
    }

    #[Route('/ecommerce/products/save', name: 'app_ecommerce_products_save', methods: ['POST'])]
    public function saveProduct(Request $request, EntityManagerInterface $em, EcomProductRepository $repo): JsonResponse
    {
        /** @var Admin $user */
        $user = $this->getUser();
        $data = json_decode($request->getContent(), true) ?: $request->request->all();

        $id = $data['id'] ?? null;
        $product = $id ? $repo->find($id) : null;

        if (!$product) {
            if (!$this->usageService->canAddProduct($user)) {
                $usage = $this->usageService->getProductUsage($user);
                return $this->json([
                    'error' => sprintf('Product limit reached. Your subscription package allows up to %d products (current: %d). Please upgrade your package.', $usage['limit'], $usage['current'])
                ], 400);
            }
            $product = new EcomProduct();
            $product->setOwner($user);
        }

        $name = trim($data['name'] ?? '');
        if ($name === '') {
            return $this->json(['error' => 'Product name is required.'], 400);
        }

        $product->setName($name);
        $product->setDescription($data['description'] ?? null);
        $product->setPrice((string) ($data['price'] ?? '0.00'));
        $product->setCurrency(strtoupper(trim($data['currency'] ?? 'USD')));
        $product->setSku($data['sku'] ?? '');
        $product->setExternalUrl($data['externalUrl'] ?? null);
        $product->setImageUrl($data['imageUrl'] ?? null);
        $product->setGalleryUrls($data['galleryUrls'] ?? []);
        $product->setCategory($data['category'] ?? null);
        $product->setStock((int) ($data['stock'] ?? 0));
        $product->setStatus($data['status'] ?? 'active');

        $em->persist($product);
        $em->flush();

        return $this->json(['success' => true, 'product' => $product->toArray()]);
    }

    #[Route('/ecommerce/products/{id}/delete', name: 'app_ecommerce_products_delete', methods: ['POST'])]
    public function deleteProduct(int $id, EcomProductRepository $repo, EntityManagerInterface $em): JsonResponse
    {
        $product = $repo->find($id);
        if (!$product) {
            return $this->json(['error' => 'Product not found.'], 404);
        }

        $em->remove($product);
        $em->flush();

        return $this->json(['success' => true]);
    }

    #[Route('/ecommerce/products/{id}/publish', name: 'app_ecommerce_products_publish', methods: ['POST'])]
    public function publishProduct(
        int $id,
        Request $request,
        EcomProductRepository $repo,
        EntityManagerInterface $em
    ): JsonResponse {
        $product = $repo->find($id);
        if (!$product) {
            return $this->json(['error' => 'Product not found.'], 404);
        }

        $data = json_decode($request->getContent(), true) ?: $request->request->all();
        $connectionIds = $data['connectionIds'] ?? [];
        $channel = $data['channel'] ?? 'facebook';
        $message = $data['message'] ?? '';
        $publishNow = ($data['publishNow'] ?? true);
        $scheduledTime = $data['scheduledTime'] ?? null;
        $timezone = $data['timezone'] ?? 'UTC';

        if (empty($connectionIds)) {
            return $this->json(['error' => 'Please select at least one connection.'], 400);
        }

        $imageUrl = $product->getImageUrl();
        $results = [];

        foreach ($connectionIds as $connId) {
            try {
                if ($channel === 'facebook') {
                    $connection = $em->getRepository(FacebookConnection::class)->find($connId);
                    if (!$connection) continue;

                    if ($publishNow) {
                        // Publish immediately
                        if ($imageUrl) {
                            $result = $this->facebookService->publishPhotoPost($connection, $imageUrl, $message);
                        } else {
                            $result = $this->facebookService->publishFeedPost($connection, $message);
                        }
                        $results[] = ['connectionId' => $connId, 'status' => 'published', 'result' => $result];
                    } else {
                        // Schedule the post via postsCache
                        $this->schedulePostToConnection($connection, $product, $message, $imageUrl, $scheduledTime, $timezone, $em);
                        $results[] = ['connectionId' => $connId, 'status' => 'scheduled'];
                    }
                } elseif ($channel === 'instagram') {
                    $connection = $em->getRepository(InstagramConnection::class)->find($connId);
                    if (!$connection) continue;

                    if ($publishNow) {
                        if ($imageUrl) {
                            $result = $this->instagramService->publishPhotoPost($connection, $imageUrl, $message);
                        } else {
                            $result = $this->instagramService->publishFeedPost($connection, $message);
                        }
                        $results[] = ['connectionId' => $connId, 'status' => 'published', 'result' => $result];
                    } else {
                        $this->scheduleIgPostToConnection($connection, $product, $message, $imageUrl, $scheduledTime, $timezone, $em);
                        $results[] = ['connectionId' => $connId, 'status' => 'scheduled'];
                    }
                }
            } catch (\Exception $e) {
                $results[] = ['connectionId' => $connId, 'status' => 'error', 'error' => $e->getMessage()];
            }
        }

        $em->flush();

        return $this->json(['success' => true, 'results' => $results]);
    }

    // ───────────────────────── Orders API ─────────────────────────

    #[Route('/ecommerce/orders', name: 'app_ecommerce_orders', methods: ['GET'])]
    public function listOrders(EcomOrderRepository $repo): JsonResponse
    {
        $orders = $repo->findBy([], ['id' => 'DESC']);
        return $this->json(['orders' => array_map(fn(EcomOrder $o) => $o->toArray(), $orders)]);
    }

    #[Route('/ecommerce/api/recent-orders', name: 'app_ecommerce_recent_orders', methods: ['GET'])]
    public function recentPendingOrders(EcomOrderRepository $repo): JsonResponse
    {
        $orders = $repo->findBy(['status' => 'pending'], ['createdAt' => 'DESC'], 5);
        $totalPending = $repo->count(['status' => 'pending']);
        return $this->json([
            'orders' => array_map(fn(EcomOrder $o) => $o->toArray(), $orders),
            'totalPending' => $totalPending
        ]);
    }

    #[Route('/ecommerce/orders/save', name: 'app_ecommerce_orders_save', methods: ['POST'])]
    public function saveOrder(Request $request, EntityManagerInterface $em, EcomOrderRepository $repo): JsonResponse
    {
        /** @var Admin $user */
        $user = $this->getUser();
        $data = json_decode($request->getContent(), true) ?: $request->request->all();

        $id = $data['id'] ?? null;
        $order = $id ? $repo->find($id) : null;

        if (!$order) {
            $order = new EcomOrder();
            $order->setOwner($user);
            $order->setOrderNumber($repo->generateOrderNumber());
        }

        $customerName = trim($data['customerName'] ?? '');
        if ($customerName === '') {
            return $this->json(['error' => 'Customer name is required.'], 400);
        }

        $order->setCustomerName($customerName);
        $order->setCustomerContact($data['customerContact'] ?? null);
        $order->setChannel($data['channel'] ?? null);
        $order->setItems($data['items'] ?? []);
        $order->setTotalAmount((string) ($data['totalAmount'] ?? '0.00'));
        $order->setCurrency(strtoupper(trim($data['currency'] ?? 'USD')));
        $order->setStatus($data['status'] ?? 'pending');
        $order->setShippingAddress($data['shippingAddress'] ?? null);
        $order->setNotes($data['notes'] ?? null);

        $em->persist($order);
        $em->flush();

        return $this->json(['success' => true, 'order' => $order->toArray()]);
    }

    #[Route('/ecommerce/orders/{id}/status', name: 'app_ecommerce_orders_status', methods: ['POST'])]
    public function updateOrderStatus(int $id, Request $request, EcomOrderRepository $repo, EntityManagerInterface $em): JsonResponse
    {
        $order = $repo->find($id);
        if (!$order) {
            return $this->json(['error' => 'Order not found.'], 404);
        }

        $data = json_decode($request->getContent(), true) ?: $request->request->all();
        $status = $data['status'] ?? '';
        $allowed = ['pending', 'approved', 'confirmed', 'delivered', 'completed', 'cancelled'];
        if (!in_array($status, $allowed, true)) {
            return $this->json(['error' => 'Invalid status.'], 400);
        }

        $order->setStatus($status);
        $em->persist($order);
        $em->flush();

        return $this->json(['success' => true, 'order' => $order->toArray()]);
    }

    #[Route('/ecommerce/orders/{id}/delete', name: 'app_ecommerce_orders_delete', methods: ['POST'])]
    public function deleteOrder(int $id, EcomOrderRepository $repo, EntityManagerInterface $em): JsonResponse
    {
        $order = $repo->find($id);
        if (!$order) {
            return $this->json(['error' => 'Order not found.'], 404);
        }

        $em->remove($order);
        $em->flush();

        return $this->json(['success' => true]);
    }

    // ───────────────────────── AI Context & Tools ─────────────────────────

    #[Route('/ecommerce/api/generate-caption', name: 'app_ecommerce_generate_caption', methods: ['POST'])]
    public function generateCaption(Request $request, EcomProductRepository $repo, \App\Service\AiAgentService $aiService): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $productId = $data['productId'] ?? null;
        $prompt = $data['prompt'] ?? '';

        if (!$productId || !$prompt) {
            return $this->json(['error' => 'Product ID and prompt are required.'], 400);
        }

        /** @var Admin $user */
        $user = $this->getUser();
        $product = $repo->findOneBy(['id' => $productId, 'owner' => $user]);

        if (!$product) {
            return $this->json(['error' => 'Product not found.'], 404);
        }

        $setting = $aiService->getEffectiveSetting();
        if (!$setting) {
            return $this->json(['error' => 'AI is not configured. Please configure an AI provider in Bot Manager.'], 400);
        }

        try {
            $caption = $aiService->generateMarketingCaption($product, $prompt, $setting);
            if (!$caption) {
                return $this->json(['error' => 'Failed to generate caption. Please check AI settings.'], 500);
            }
            return $this->json(['success' => true, 'caption' => $caption]);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }

    #[Route('/ecommerce/ai-context', name: 'app_ecommerce_ai_context', methods: ['GET'])]
    public function aiContext(EcomProductRepository $repo): JsonResponse
    {
        $products = $repo->findBy(['status' => 'active'], ['name' => 'ASC']);
        $lines = ["=== Product Catalog ===\n"];

        foreach ($products as $p) {
            $stockLabel = $p->getStock() > 0 ? "In Stock ({$p->getStock()} available)" : 'Available';
            $lines[] = "• {$p->getName()} — {$p->getCurrency()} {$p->getPrice()} — {$stockLabel}";
            if ($p->getDescription()) {
                $lines[] = "  {$p->getDescription()}";
            }
        }

        $contextText = implode("\n", $lines);

        return $this->json(['context' => $contextText, 'productCount' => count($products)]);
    }

    // ───────────────────────── Settings API ─────────────────────────

    #[Route('/ecommerce/settings', name: 'app_ecommerce_settings', methods: ['GET'])]
    public function getSettings(EcomSettingRepository $repo): JsonResponse
    {
        /** @var Admin $user */
        $user = $this->getUser();
        $setting = $repo->findOneBy(['owner' => $user]);
        
        if (!$setting) {
            $setting = new EcomSetting();
            $setting->setOwner($user);
            // Default settings
            $setting->setCheckoutEnabled(true);
            $setting->setPaymentInstructions('');
        }

        return $this->json(['settings' => $setting->toArray()]);
    }

    #[Route('/ecommerce/settings/save', name: 'app_ecommerce_settings_save', methods: ['POST'])]
    public function saveSettings(Request $request, EntityManagerInterface $em, EcomSettingRepository $repo): JsonResponse
    {
        /** @var Admin $user */
        $user = $this->getUser();
        $data = json_decode($request->getContent(), true) ?: $request->request->all();

        $setting = $repo->findOneBy(['owner' => $user]);
        if (!$setting) {
            $setting = new EcomSetting();
            $setting->setOwner($user);
        }

        $setting->setPaymentInstructions($data['paymentInstructions'] ?? null);
        $setting->setCheckoutEnabled($data['checkoutEnabled'] ?? true);
        $setting->setGlobalExternalUrl($data['globalExternalUrl'] ?? null);

        $em->persist($setting);
        $em->flush();

        return $this->json(['success' => true, 'settings' => $setting->toArray()]);
    }

    // ───────────────────────── Private Helpers ─────────────────────────

    private function schedulePostToConnection(
        FacebookConnection $connection,
        EcomProduct $product,
        string $message,
        ?string $imageUrl,
        ?string $scheduledTime,
        string $timezone,
        EntityManagerInterface $em
    ): void {
        $cache = $connection->getPostsCache() ?: [];
        $posts = $cache['posts'] ?? [];

        // Convert scheduled time to UTC
        $scheduledAtUtc = null;
        if ($scheduledTime) {
            try {
                $dt = new \DateTime($scheduledTime, new \DateTimeZone($timezone));
                $dt->setTimezone(new \DateTimeZone('UTC'));
                $scheduledAtUtc = $dt->format('Y-m-d H:i:s');
            } catch (\Exception $e) {
                $scheduledAtUtc = $scheduledTime;
            }
        }

        $posts[] = [
            'id' => 'ecom_' . $product->getId() . '_' . time(),
            'type' => 'multimedia',
            'message' => $message,
            'mediaType' => $imageUrl ? 'image' : 'none',
            'mediaUrl' => $imageUrl ?? '',
            'link' => '',
            'ctaType' => '',
            'slides' => [],
            'status' => 'scheduled',
            'scheduledAt' => $scheduledAtUtc,
            'source' => 'ecommerce',
            'productId' => $product->getId(),
            'createdAt' => (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
        ];

        $cache['posts'] = $posts;
        $cache['updatedAt'] = time();
        $connection->setPostsCache($cache);
        $em->persist($connection);
    }

    private function scheduleIgPostToConnection(
        InstagramConnection $connection,
        EcomProduct $product,
        string $message,
        ?string $imageUrl,
        ?string $scheduledTime,
        string $timezone,
        EntityManagerInterface $em
    ): void {
        $cache = $connection->getPostsCache() ?: [];
        $posts = $cache['posts'] ?? [];

        $scheduledAtUtc = null;
        if ($scheduledTime) {
            try {
                $dt = new \DateTime($scheduledTime, new \DateTimeZone($timezone));
                $dt->setTimezone(new \DateTimeZone('UTC'));
                $scheduledAtUtc = $dt->format('Y-m-d H:i:s');
            } catch (\Exception $e) {
                $scheduledAtUtc = $scheduledTime;
            }
        }

        $posts[] = [
            'id' => 'ecom_' . $product->getId() . '_' . time(),
            'type' => 'multimedia',
            'message' => $message,
            'mediaType' => $imageUrl ? 'image' : 'none',
            'mediaUrl' => $imageUrl ?? '',
            'link' => '',
            'ctaType' => '',
            'slides' => [],
            'status' => 'scheduled',
            'scheduledAt' => $scheduledAtUtc,
            'source' => 'ecommerce',
            'productId' => $product->getId(),
            'createdAt' => (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
        ];

        $cache['posts'] = $posts;
        $cache['updatedAt'] = time();
        $connection->setPostsCache($cache);
        $em->persist($connection);
    }
}
