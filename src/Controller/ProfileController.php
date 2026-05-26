<?php

namespace App\Controller;

use App\Entity\Admin;
use App\Repository\WebauthnCredentialRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Webauthn\PublicKeyCredentialUserEntity;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
class ProfileController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        private WebauthnCredentialRepository $webauthnRepository
    ) {
    }

    #[Route('/profile', name: 'app_accounts_edit_self', methods: ['GET', 'POST'])]
    public function editSelf(Request $request, \App\Service\R2SettingsService $r2SettingsService): Response
    {
        /** @var Admin $currentUser */
        $currentUser = $this->getUser();
        if (!$currentUser) {
            throw $this->createAccessDeniedException('Access denied.');
        }

        if ($request->isMethod('POST')) {
            $email = $request->request->get('email');
            $plainPassword = $request->request->get('password');
            $name = $request->request->get('name');
            $avatarPreset = $request->request->get('avatar_preset');
            $avatarFile = $request->files->get('avatar_file');

            if (empty($email)) {
                $this->addFlash('error', 'Email is required.');
                return $this->render('profile/edit.html.twig', [
                    'account' => $currentUser,
                    'currentUser' => $currentUser,
                    'passkeys' => $this->getPasskeysForUser($currentUser),
                ]);
            }

            $existing = $this->entityManager->getRepository(Admin::class)->findOneBy(['email' => $email]);
            if ($existing && $existing->getId() !== $currentUser->getId()) {
                $this->addFlash('error', 'Email already in use.');
                return $this->render('profile/edit.html.twig', [
                    'account' => $currentUser,
                    'currentUser' => $currentUser,
                    'passkeys' => $this->getPasskeysForUser($currentUser),
                ]);
            }

            $currentUser->setEmail($email);
            $currentUser->setName($name);

            $avatarUrl = $request->request->get('avatar_url');
            $avatarPreset = $request->request->get('avatar_preset');

            $settings = $r2SettingsService->getActiveSettings($currentUser);
            $isR2Configured = $r2SettingsService->isComplete($settings);

            if ($avatarFile && !$isR2Configured) {
                $originalFilename = pathinfo($avatarFile->getClientOriginalName(), PATHINFO_FILENAME);
                $safeFilename = preg_replace('/[^a-zA-Z0-9_]/', '', $originalFilename);
                $newFilename = $safeFilename.'-'.uniqid().'.'.$avatarFile->guessExtension();

                try {
                    $avatarFile->move(
                        $this->getParameter('kernel.project_dir').'/public/uploads/avatars',
                        $newFilename
                    );
                    $currentUser->setAvatar('uploads/avatars/'.$newFilename);
                } catch (\Exception $e) {
                    $this->addFlash('error', 'Failed to upload avatar: '.$e->getMessage());
                }
            } elseif (!empty($avatarUrl)) {
                $currentUser->setAvatar($avatarUrl);
            } elseif (!empty($avatarPreset)) {
                if ($avatarPreset === 'clear') {
                    $currentUser->setAvatar(null);
                } else {
                    $currentUser->setAvatar('preset:'.$avatarPreset);
                }
            }

            if (!empty($plainPassword)) {
                $currentPassword = $request->request->get('current_password');
                if (empty($currentPassword) || !$this->passwordHasher->isPasswordValid($currentUser, $currentPassword)) {
                    $this->addFlash('error', 'Current password is required and must be correct to change your password.');
                    return $this->render('profile/edit.html.twig', [
                        'account' => $currentUser,
                        'currentUser' => $currentUser,
                        'owners' => [],
                        'passkeys' => $this->getPasskeysForUser($currentUser),
                    ]);
                }
                $currentUser->setPassword($this->passwordHasher->hashPassword($currentUser, $plainPassword));
            }

            // Normal users can toggle their own team_enabled switch if they want, but only if they are the owner
            if ($currentUser->getAccountType() === 'user' && $currentUser->getParent() === null) {
                $teamEnabled = $request->request->getBoolean('team_enabled', false);
                $currentUser->setTeamEnabled($teamEnabled);
            }

            $this->entityManager->flush();
            $this->addFlash('success', 'Profile updated successfully.');

            return $this->redirectToRoute('app_accounts_edit_self');
        }

        return $this->render('profile/edit.html.twig', [
            'account' => $currentUser,
            'currentUser' => $currentUser,
            'owners' => [], // Not used for self edit
            'passkeys' => $this->getPasskeysForUser($currentUser),
        ]);
    }

    #[Route('/settings/theme-toggle', name: 'app_theme_toggle', methods: ['POST'])]
    public function toggleTheme(Request $request): Response
    {
        /** @var Admin $user */
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['success' => false, 'error' => 'Not authenticated'], 403);
        }

        $data = json_decode($request->getContent(), true);
        $theme = $data['theme'] ?? 'dark';

        if (!in_array($theme, ['dark', 'light'])) {
            return $this->json(['success' => false, 'error' => 'Invalid theme'], 400);
        }

        $user->setTheme($theme);
        $this->entityManager->flush();

        return $this->json(['success' => true]);
    }

    #[Route('/profile/passkey/{id}/delete', name: 'app_passkey_delete', methods: ['POST'])]
    public function deletePasskey(string $id, Request $request): JsonResponse
    {
        /** @var Admin $currentUser */
        $currentUser = $this->getUser();
        if (!$currentUser) {
            return $this->json(['success' => false, 'error' => 'Not authenticated'], 403);
        }

        $credential = $this->webauthnRepository->find($id);
        if (!$credential) {
            return $this->json(['success' => false, 'error' => 'Passkey not found'], 404);
        }

        // Verify the passkey belongs to the current user
        if ($credential->userHandle !== (string) $currentUser->getId()) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], 403);
        }

        $this->entityManager->remove($credential);
        $this->entityManager->flush();

        return $this->json(['success' => true]);
    }

    private function getPasskeysForUser(Admin $user): array
    {
        $userEntity = new PublicKeyCredentialUserEntity(
            $user->getEmail(),
            (string) $user->getId(),
            $user->getName() ?? $user->getEmail()
        );
        return $this->webauthnRepository->findAllForUserEntity($userEntity);
    }
}
