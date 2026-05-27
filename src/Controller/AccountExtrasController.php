<?php

namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/profile')]
#[IsGranted('ROLE_USER')]
class AccountExtrasController extends AbstractController
{
    #[Route('/security', name: 'app_profile_security', methods: ['GET'])]
    public function security(EntityManagerInterface $em, \Symfony\Component\HttpFoundation\RequestStack $requestStack): Response
    {
        $user = $this->getUser();
        $sessions = $em->getRepository(\App\Entity\UserSession::class)->findBy(['admin' => $user], ['lastActivityAt' => 'DESC']);
        
        $currentSessionId = null;
        if ($requestStack->getCurrentRequest() && $requestStack->getCurrentRequest()->hasSession()) {
            $currentSessionId = $requestStack->getCurrentRequest()->getSession()->getId();
        }

        return $this->render('profile/security.html.twig', [
            'sessions' => $sessions,
            'currentSessionId' => $currentSessionId
        ]);
    }
    
    #[Route('/security/session/{id}/revoke', name: 'app_profile_security_session_revoke', methods: ['POST'])]
    public function revokeSession(int $id, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        $session = $em->getRepository(\App\Entity\UserSession::class)->findOneBy(['id' => $id, 'admin' => $user]);
        
        if (!$session) {
            return new JsonResponse(['success' => false, 'error' => 'Session not found or already revoked.'], 404);
        }
        
        // Remove the session from DB
        $em->remove($session);
        $em->flush();
        
        return new JsonResponse(['success' => true, 'message' => 'Session revoked successfully.']);
    }

    #[Route('/security/passphrase', name: 'app_profile_security_passphrase', methods: ['POST'])]
    public function updatePassphrase(Request $request, UserPasswordHasherInterface $passwordHasher, EntityManagerInterface $em): JsonResponse
    {
        /** @var \App\Entity\Admin $user */
        $user = $this->getUser();
        $data = json_decode($request->getContent(), true);
        
        $currentPassword = $data['current_password'] ?? '';
        $newPassword = $data['new_password'] ?? '';
        
        if (empty($currentPassword) || empty($newPassword)) {
            return new JsonResponse(['success' => false, 'error' => 'Current and new passphrase are required.'], 400);
        }
        
        if (strlen($newPassword) < 8) {
            return new JsonResponse(['success' => false, 'error' => 'New passphrase must be at least 8 characters long.'], 400);
        }
        
        if (!$passwordHasher->isPasswordValid($user, $currentPassword)) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid current passphrase.'], 400);
        }
        
        $user->setPassword($passwordHasher->hashPassword($user, $newPassword));
        $em->flush();
        
        return new JsonResponse(['success' => true, 'message' => 'Passphrase updated successfully.']);
    }

    #[Route('/security/2fa/generate', name: 'app_profile_security_2fa_generate', methods: ['POST'])]
    public function generate2faSecret(\Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface $totpAuthenticator, EntityManagerInterface $em): JsonResponse
    {
        /** @var \App\Entity\Admin $user */
        $user = $this->getUser();
        
        if (!$user->getTotpSecret()) {
            $user->setTotpSecret($totpAuthenticator->generateSecret());
            $em->flush();
        }
        
        $qrContent = $totpAuthenticator->getQRContent($user);
        
        return new JsonResponse([
            'success' => true,
            'secret' => $user->getTotpSecret(),
            'qrContent' => $qrContent
        ]);
    }

    #[Route('/security/2fa/enable', name: 'app_profile_security_2fa_enable', methods: ['POST'])]
    public function enable2fa(Request $request, \Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface $totpAuthenticator, EntityManagerInterface $em): JsonResponse
    {
        /** @var \App\Entity\Admin $user */
        $user = $this->getUser();
        $data = json_decode($request->getContent(), true);
        $code = $data['code'] ?? '';
        
        if (empty($code)) {
            return new JsonResponse(['success' => false, 'error' => 'Verification code is required.'], 400);
        }
        
        if (!$totpAuthenticator->checkCode($user, $code)) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid verification code. Try again.'], 400);
        }
        
        $user->setIsTotpAuthenticationEnabled(true);
        $em->flush();
        
        return new JsonResponse(['success' => true, 'message' => 'Two-Factor Authentication is now enabled.']);
    }

    #[Route('/security/2fa/disable', name: 'app_profile_security_2fa_disable', methods: ['POST'])]
    public function disable2fa(EntityManagerInterface $em): JsonResponse
    {
        /** @var \App\Entity\Admin $user */
        $user = $this->getUser();
        $user->setIsTotpAuthenticationEnabled(false);
        $user->setTotpSecret(null);
        $em->flush();
        
        return new JsonResponse(['success' => true, 'message' => 'Two-Factor Authentication has been disabled.']);
    }

    #[Route('/security/2fa/status', name: 'app_profile_security_2fa_status', methods: ['GET'])]
    public function status2fa(): JsonResponse
    {
        /** @var \App\Entity\Admin $user */
        $user = $this->getUser();
        return new JsonResponse([
            'enabled' => $user->isTotpAuthenticationEnabled()
        ]);
    }

    #[Route('/developer', name: 'app_profile_developer', methods: ['GET'])]
    public function developer(): Response
    {
        return $this->render('profile/developer.html.twig');
    }
}
