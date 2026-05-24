<?php

namespace App\Controller;

use App\Entity\CloudflareSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class CloudflareSettingsController extends AbstractController
{
    #[Route('/settings/cloudflare', name: 'app_cloudflare_settings')]
    public function index(Request $request, EntityManagerInterface $entityManager): Response
    {
        /** @var \App\Entity\Admin $user */
        $user = $this->getUser();
        
        if ($user->getAccountType() !== 'super_admin') {
            throw $this->createAccessDeniedException('Only Super Admins can access Cloudflare Settings.');
        }
        
        $settings = $user->getCloudflareSettings();
        if (!$settings) {
            $settings = new CloudflareSettings();
            $settings->setOwner($user);
        }

        if ($request->isMethod('POST')) {
            $helperDomain = $request->request->get('helperDomain');
            $apiToken = $request->request->get('apiToken');
            $zoneId = $request->request->get('zoneId');

            $settings->setHelperDomain(empty($helperDomain) ? null : $helperDomain);
            $settings->setApiToken(empty($apiToken) ? null : $apiToken);
            $settings->setZoneId(empty($zoneId) ? null : $zoneId);

            $entityManager->persist($settings);
            $entityManager->flush();

            $this->addFlash('success', 'Cloudflare settings saved successfully.');

            return $this->redirectToRoute('app_cloudflare_settings');
        }

        return $this->render('cloudflare_settings/index.html.twig', [
            'settings' => $settings,
        ]);
    }
}
