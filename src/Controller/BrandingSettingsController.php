<?php

namespace App\Controller;

use App\Entity\Admin;
use App\Entity\ResellerBranding;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use App\Service\CloudflareService;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
class BrandingSettingsController extends AbstractController
{
    #[Route('/settings/branding', name: 'app_branding_settings', methods: ['GET', 'POST'])]
    public function index(Request $request, EntityManagerInterface $entityManager, CloudflareService $cfService, \App\Service\R2SettingsService $r2SettingsService): Response
    {
        /** @var Admin $user */
        $user = $this->getUser();

        if (!in_array($user->getAccountType(), ['super_admin', 'admin'])) {
            throw $this->createAccessDeniedException('Only workspace owners can configure branding settings.');
        }

        $branding = $user->getBranding();
        if (!$branding) {
            $branding = new ResellerBranding();
            $branding->setOwner($user);
        }

        if ($request->isMethod('POST')) {
            $customDomain = $request->request->get('customDomain');
            $brandName = $request->request->get('brandName');
            $supportEmail = $request->request->get('supportEmail');

            if (empty($customDomain)) {
                $customDomain = null;
            } else {
                // Ensure no http/https prefixes or paths
                $customDomain = preg_replace('#^https?://#', '', $customDomain);
                $customDomain = explode('/', $customDomain)[0];
            }

            // Verify unique domain if changed
            if ($customDomain !== $branding->getCustomDomain()) {
                if ($customDomain) {
                    $existing = $entityManager->getRepository(ResellerBranding::class)->findOneBy(['customDomain' => $customDomain]);
                    if ($existing && $existing->getOwner()->getId() !== $user->getId()) {
                        $this->addFlash('error', 'This custom domain is already registered by another workspace.');
                        return $this->render('branding_settings/index.html.twig', [
                            'branding' => $branding,
                        ]);
                    }
                }

                // If domain changed, we need to deal with Cloudflare
                $superAdmin = $entityManager->getRepository(Admin::class)->findOneBy(['accountType' => 'super_admin']);
                $cfSettings = $superAdmin ? $superAdmin->getCloudflareSettings() : null;

                if ($cfSettings && $cfSettings->getApiToken() && $cfSettings->getZoneId()) {
                    // Delete old hostname if exists
                    if ($branding->getCloudflareHostnameId()) {
                        $cfService->deleteCustomHostname($branding->getCloudflareHostnameId(), $cfSettings);
                        $branding->setCloudflareHostnameId(null);
                        $branding->setSslValidationName(null);
                        $branding->setSslValidationValue(null);
                        $branding->setSslStatus(null);
                    }

                    // Add new hostname if domain is provided
                    if ($customDomain) {
                        try {
                            $cfResult = $cfService->addCustomHostname($customDomain, $cfSettings);
                            $branding->setCloudflareHostnameId($cfResult['id']);
                            $branding->setSslValidationName($cfResult['validation_name']);
                            $branding->setSslValidationValue($cfResult['validation_value']);
                            $branding->setSslStatus($cfResult['status']);
                        } catch (\Exception $e) {
                            $this->addFlash('error', 'Cloudflare API Error: ' . $e->getMessage() . '. Please contact support to manually configure your SSL.');
                        }
                    }
                } else {
                    if ($customDomain) {
                        $this->addFlash('warning', 'Cloudflare API is not fully configured by the Super Admin. Please contact support to get your SSL validation string.');
                    }
                }
            }

            $branding->setCustomDomain($customDomain);
            $branding->setBrandName($brandName);
            
            $customCss = $request->request->get('customCss');
            $branding->setCustomCss($customCss);
            
            // Handle logo URL or fallback file upload
            $logoUrl = $request->request->get('logoUrl');
            $logoFile = $request->files->get('logoFile');

            $settings = $r2SettingsService->getActiveSettings($user);
            $isR2Configured = $r2SettingsService->isComplete($settings);

            if ($logoFile && !$isR2Configured) {
                $originalFilename = pathinfo($logoFile->getClientOriginalName(), PATHINFO_FILENAME);
                $safeFilename = preg_replace('/[^a-zA-Z0-9_]/', '', $originalFilename);
                $newFilename = $safeFilename.'-'.uniqid().'.'.$logoFile->guessExtension();

                try {
                    $logoFile->move(
                        $this->getParameter('kernel.project_dir').'/public/uploads/branding',
                        $newFilename
                    );
                    $branding->setLogoUrl('uploads/branding/'.$newFilename);
                } catch (\Exception $e) {
                    $this->addFlash('error', 'Failed to upload logo: '.$e->getMessage());
                }
            } elseif (!empty($logoUrl)) {
                $branding->setLogoUrl($logoUrl);
            }

            $entityManager->persist($branding);
            $entityManager->flush();

            $this->addFlash('success', 'Branding settings updated successfully.');
            return $this->redirectToRoute('app_branding_settings');
        }

        return $this->render('branding_settings/index.html.twig', [
            'branding' => $branding,
        ]);
    }
}
