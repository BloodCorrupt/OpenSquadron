<?php

namespace App\Controller;

use App\Entity\Admin;
use App\Entity\SubscriptionPackage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/subscription-packages')]
#[IsGranted('ROLE_ADMIN')]
class SubscriptionPackageController extends AbstractController
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    #[Route('', name: 'app_subscription_packages_index', methods: ['GET'])]
    public function index(): Response
    {
        /** @var Admin $user */
        $user = $this->getUser();

        if (!in_array($user->getAccountType(), ['super_admin', 'reseller', 'admin'])) {
            throw $this->createAccessDeniedException('Only Super Admins and Resellers can manage subscription packages.');
        }

        $packages = $this->entityManager->getRepository(SubscriptionPackage::class)->findBy(['owner' => $user]);

        return $this->render('subscription_package/index.html.twig', [
            'packages' => $packages,
            'user' => $user,
        ]);
    }

    #[Route('/new', name: 'app_subscription_packages_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        /** @var Admin $user */
        $user = $this->getUser();

        if (!in_array($user->getAccountType(), ['super_admin', 'reseller', 'admin'])) {
            throw $this->createAccessDeniedException('Access denied.');
        }

        if ($request->isMethod('POST')) {
            $name = $request->request->get('name');
            $price = $request->request->get('price', 0);
            $validityDays = $request->request->get('validity_days', 30);
            $isResellerPackage = $request->request->getBoolean('is_reseller_package', false);
            $isDefault = $request->request->getBoolean('is_default', false);
            $isLifetime = $request->request->getBoolean('is_lifetime', false);

            $features = [
                'modules' => $request->request->all('modules') ?? [],
                'limits' => [
                    'bots' => $request->request->getInt('limit_bots', 0),
                    'team' => $request->request->getInt('limit_team', 0),
                    'messages' => $request->request->getInt('limit_messages', 0),
                    'products' => $request->request->getInt('limit_products', 0),
                ],
                'media_storage_mode' => $request->request->get('media_storage_mode', 'parent')
            ];

            if (empty($name)) {
                $this->addFlash('error', 'Package name is required.');
                return $this->redirectToRoute('app_subscription_packages_new');
            }

            $package = new SubscriptionPackage();
            $package->setOwner($user);
            $package->setName($name);
            $package->setPrice((float) $price);
            $package->setValidityDays((int) $validityDays);
            $package->setLifetime($isLifetime);
            
            // Handle isDefault logic (only one package can be default per owner)
            if ($isDefault) {
                $existingDefaults = $this->entityManager->getRepository(SubscriptionPackage::class)->findBy(['owner' => $user, 'isDefault' => true]);
                foreach ($existingDefaults as $def) {
                    $def->setDefault(false);
                }
            }
            $package->setDefault($isDefault);
            
            if ($user->getAccountType() === 'super_admin') {
                $package->setResellerPackage($isResellerPackage);
            }

            $package->setFeatures($features);

            $this->entityManager->persist($package);
            $this->entityManager->flush();

            $this->addFlash('success', 'Subscription package created successfully.');
            return $this->redirectToRoute('app_subscription_packages_index');
        }

        return $this->render('subscription_package/form.html.twig', [
            'package' => null,
            'user' => $user,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_subscription_packages_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, int $id): Response
    {
        /** @var Admin $user */
        $user = $this->getUser();

        if (!in_array($user->getAccountType(), ['super_admin', 'reseller', 'admin'])) {
            throw $this->createAccessDeniedException('Access denied.');
        }

        $package = $this->entityManager->getRepository(SubscriptionPackage::class)->find($id);

        if (!$package || $package->getOwner() !== $user) {
            throw $this->createNotFoundException('Package not found or access denied.');
        }

        if ($request->isMethod('POST')) {
            $name = $request->request->get('name');
            $price = $request->request->get('price', 0);
            $validityDays = $request->request->get('validity_days', 30);
            $isResellerPackage = $request->request->getBoolean('is_reseller_package', false);
            $isDefault = $request->request->getBoolean('is_default', false);
            $isLifetime = $request->request->getBoolean('is_lifetime', false);

            $features = [
                'modules' => $request->request->all('modules') ?? [],
                'limits' => [
                    'bots' => $request->request->getInt('limit_bots', 0),
                    'team' => $request->request->getInt('limit_team', 0),
                    'messages' => $request->request->getInt('limit_messages', 0),
                    'products' => $request->request->getInt('limit_products', 0),
                ],
                'media_storage_mode' => $request->request->get('media_storage_mode', 'parent')
            ];

            if (empty($name)) {
                $this->addFlash('error', 'Package name is required.');
                return $this->redirectToRoute('app_subscription_packages_edit', ['id' => $id]);
            }

            $package->setName($name);
            $package->setPrice((float) $price);
            $package->setValidityDays((int) $validityDays);
            $package->setLifetime($isLifetime);
            
            if ($isDefault) {
                $existingDefaults = $this->entityManager->getRepository(SubscriptionPackage::class)->findBy(['owner' => $user, 'isDefault' => true]);
                foreach ($existingDefaults as $def) {
                    if ($def->getId() !== $package->getId()) {
                        $def->setDefault(false);
                    }
                }
            }
            $package->setDefault($isDefault);
            
            if ($user->getAccountType() === 'super_admin') {
                $package->setResellerPackage($isResellerPackage);
            }

            $package->setFeatures($features);

            $this->entityManager->flush();

            $this->addFlash('success', 'Subscription package updated successfully.');
            return $this->redirectToRoute('app_subscription_packages_index');
        }

        return $this->render('subscription_package/form.html.twig', [
            'package' => $package,
            'user' => $user,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_subscription_packages_delete', methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        /** @var Admin $user */
        $user = $this->getUser();

        $package = $this->entityManager->getRepository(SubscriptionPackage::class)->find($id);

        if (!$package || $package->getOwner() !== $user) {
            throw $this->createNotFoundException('Package not found or access denied.');
        }

        // Optional: Check if any users are currently subscribed to this package before deleting.
        // For simplicity, we just delete or set to null on cascade (which we did in Admin Entity).

        $this->entityManager->remove($package);
        $this->entityManager->flush();

        $this->addFlash('success', 'Subscription package deleted successfully.');

        return $this->redirectToRoute('app_subscription_packages_index');
    }
}
