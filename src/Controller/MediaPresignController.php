<?php

namespace App\Controller;

use App\Service\R2SettingsService;
use App\Service\R2SignerService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
class MediaPresignController extends AbstractController
{
    #[Route('/settings/media/presign', name: 'app_media_presign', methods: ['POST'])]
    public function presign(
        Request $request,
        R2SettingsService $settingsService,
        R2SignerService $signerService
    ): JsonResponse {
        /** @var \App\Entity\Admin $user */
        $user = $this->getUser();

        $data = json_decode($request->getContent(), true) ?? $request->request->all();

        $filename = $data['filename'] ?? '';
        $contentType = $data['contentType'] ?? '';
        $prefix = $data['prefix'] ?? 'general';

        if (empty($filename) || empty($contentType)) {
            return new JsonResponse(['error' => 'Filename and Content-Type are required.'], 400);
        }

        // Validate prefix to prevent arbitrary paths
        $allowedPrefixes = ['avatars', 'branding', 'whatsapp', 'posts'];
        if (!in_array($prefix, $allowedPrefixes, true)) {
            return new JsonResponse(['error' => 'Invalid upload prefix.'], 400);
        }

        $settings = $settingsService->getActiveSettings($user);
        if (!$settingsService->isComplete($settings)) {
            return new JsonResponse([
                'error' => 'Storage settings are not configured or are incomplete. Please contact support.',
                'code' => 'R2_NOT_CONFIGURED'
            ], 400);
        }

        // Generate a unique object key
        $pathInfo = pathinfo($filename);
        $extension = $pathInfo['extension'] ?? '';
        $safeName = preg_replace('/[^a-zA-Z0-9_]/', '', $pathInfo['filename']);
        if (empty($safeName)) {
            $safeName = 'file';
        }
        $objectKey = "{$prefix}/{$safeName}-" . uniqid() . ($extension ? ".{$extension}" : "");

        try {
            $uploadUrl = $signerService->generatePresignedPutUrl(
                $settings->getAccountId(),
                $settings->getAccessKeyId(),
                $settings->getSecretAccessKey(),
                $settings->getBucketName(),
                $objectKey,
                $contentType
            );

            // Construct the final public URL
            $publicBaseUrl = $settings->getPublicUrl();
            if ($publicBaseUrl) {
                $publicUrl = rtrim($publicBaseUrl, '/') . '/' . ltrim($objectKey, '/');
            } else {
                $publicUrl = "https://{$settings->getBucketName()}.{$settings->getAccountId()}.r2.cloudflarestorage.com/" . ltrim($objectKey, '/');
            }

            return new JsonResponse([
                'uploadUrl' => $uploadUrl,
                'publicUrl' => $publicUrl,
                'key' => $objectKey,
            ]);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => 'Failed to generate presigned upload URL: ' . $e->getMessage()], 500);
        }
    }

    #[Route('/settings/media/upload-local', name: 'app_media_upload_local', methods: ['POST'])]
    public function uploadLocal(Request $request): JsonResponse
    {
        $file = $request->files->get('file');
        $prefix = $request->request->get('prefix', 'posts');

        if (!$file) {
            return new JsonResponse(['error' => 'No file uploaded.'], 400);
        }

        $allowedPrefixes = ['avatars', 'branding', 'whatsapp', 'posts'];
        if (!in_array($prefix, $allowedPrefixes, true)) {
            return new JsonResponse(['error' => 'Invalid upload prefix.'], 400);
        }

        $originalFilename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $safeFilename = preg_replace('/[^a-zA-Z0-9_]/', '', $originalFilename);
        if (empty($safeFilename)) {
            $safeFilename = 'file';
        }
        $newFilename = $safeFilename . '-' . uniqid() . '.' . $file->guessExtension();

        try {
            $targetDirectory = $this->getParameter('kernel.project_dir') . '/public/uploads/' . $prefix;
            $file->move($targetDirectory, $newFilename);
            
            $publicUrl = $request->getSchemeAndHttpHost() . '/uploads/' . $prefix . '/' . $newFilename;

            return new JsonResponse([
                'success' => true,
                'publicUrl' => $publicUrl,
                'path' => 'uploads/' . $prefix . '/' . $newFilename
            ]);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => 'Failed to upload file locally: ' . $e->getMessage()], 500);
        }
    }
}
