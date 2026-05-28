<?php

namespace App\Controller;

use App\Entity\R2Settings;
use App\Service\R2SettingsService;
use App\Service\R2StorageService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class MediaSettingsController extends AbstractController
{
    #[Route('/settings/media', name: 'app_media_storage_settings', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        EntityManagerInterface $entityManager,
        R2SettingsService $settingsService,
        R2StorageService $storageService
    ): Response
    {
        /** @var \App\Entity\Admin $user */
        $user = $this->getUser();

        $canConfigure = false;
        $isUserChoice = false;
        $isUserEnforced = false;

        if (in_array($user->getAccountType(), ['super_admin', 'admin'], true)) {
            $canConfigure = true;
        } else {
            $package = $user->getSubscriptionPackage();
            if ($package) {
                $features = $package->getFeatures();
                $mode = $features['media_storage_mode'] ?? 'parent';
                if ($mode === 'user') {
                    $canConfigure = true;
                    $isUserEnforced = true;
                } elseif ($mode === 'choice') {
                    $canConfigure = true;
                    $isUserChoice = true;
                }
            }
        }

        if (!$canConfigure) {
            throw $this->createAccessDeniedException('Access denied. Your package does not allow configuring custom media storage.');
        }

        $settings = $user->getR2Settings();
        if (!$settings) {
            $settings = new R2Settings();
            $settings->setOwner($user);
        }

        if ($request->isMethod('POST')) {
            $accountId = $request->request->get('accountId');
            $accessKeyId = $request->request->get('accessKeyId');
            $secretAccessKey = $request->request->get('secretAccessKey');
            $bucketName = $request->request->get('bucketName');
            $publicUrl = $request->request->get('publicUrl');
            $useCustom = $request->request->getBoolean('useCustom', false);

            $settings->setAccountId(empty($accountId) ? null : trim($accountId));
            $settings->setAccessKeyId(empty($accessKeyId) ? null : trim($accessKeyId));
            $settings->setSecretAccessKey(empty($secretAccessKey) ? null : trim($secretAccessKey));
            $settings->setBucketName(empty($bucketName) ? null : trim($bucketName));
            $settings->setPublicUrl(empty($publicUrl) ? null : trim($publicUrl));

            $inboxRetentionDays = $request->request->get('inboxRetentionDays');
            if ($inboxRetentionDays === '' || $inboxRetentionDays === null || (int)$inboxRetentionDays === 0) {
                $settings->setInboxRetentionDays(null);
            } else {
                $settings->setInboxRetentionDays((int)$inboxRetentionDays);
            }

            if ($isUserChoice) {
                $settings->setUseCustom($useCustom);
            } else {
                $settings->setUseCustom(true);
            }

            $entityManager->persist($settings);
            $entityManager->flush();

            if ($settingsService->isComplete($settings)) {
                $lifecycleSuccess = $storageService->updateBucketLifecycle($settings, $settings->getInboxRetentionDays());
                if (!$lifecycleSuccess) {
                    $this->addFlash('warning', 'Settings saved locally, but failed to update the Auto-delete configuration on Cloudflare R2. Please ensure your R2 Access Key has permission to manage bucket lifecycle settings.');
                }
            }

            $this->addFlash('success', 'Media storage settings saved successfully.');

            return $this->redirectToRoute('app_media_storage_settings');
        }

        return $this->render('media_settings/index.html.twig', [
            'settings' => $settings,
            'isUserChoice' => $isUserChoice,
            'isUserEnforced' => $isUserEnforced,
            'user' => $user,
        ]);
    }
}
