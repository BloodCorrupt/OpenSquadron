<?php

namespace App\Controller;

use App\Entity\Admin;
use App\Service\SmtpMailerService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

class ResetPasswordController extends AbstractController
{
    #[Route('/forgot-password', name: 'app_forgot_password', methods: ['GET', 'POST'])]
    public function forgotPassword(
        Request $request,
        EntityManagerInterface $entityManager,
        SmtpMailerService $smtpMailer
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        if ($request->isMethod('POST')) {
            $email = $request->request->get('email');

            $user = $entityManager->getRepository(Admin::class)->findOneBy(['email' => $email]);

            if ($user) {
                // Find the creator to use their SMTP settings
                $creator = $user->getCreatedBy();
                if (!$creator && in_array($user->getAccountType(), ['super_admin', 'admin'])) {
                    // It's the owner themselves
                    $creator = $user;
                }

                if ($creator && $creator->getSmtpSettings()) {
                    $token = bin2hex(random_bytes(32));
                    $user->setResetToken($token);
                    $user->setResetTokenExpiresAt(new \DateTimeImmutable('+1 hour'));
                    
                    $entityManager->flush();

                    try {
                        $smtpMailer->sendPasswordResetEmail($creator, $user, $token);
                    } catch (\Exception $e) {
                        // Log or ignore to prevent email enumeration
                    }
                }
            }

            // Always show the same success message to prevent email enumeration
            $this->addFlash('success', 'If an account exists with that email, a password reset link has been sent.');
            return $this->redirectToRoute('app_login');
        }

        return $this->render('reset_password/forgot.html.twig');
    }

    #[Route('/reset-password', name: 'app_reset_password', methods: ['GET', 'POST'])]
    public function resetPassword(
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher
    ): Response {
        $token = $request->query->get('token');

        if (!$token) {
            $this->addFlash('error', 'No reset token provided.');
            return $this->redirectToRoute('app_forgot_password');
        }

        $user = $entityManager->getRepository(Admin::class)->findOneBy(['resetToken' => $token]);

        if (!$user) {
            $this->addFlash('error', 'Invalid or expired reset token.');
            return $this->redirectToRoute('app_forgot_password');
        }

        if ($user->getResetTokenExpiresAt() !== null && $user->getResetTokenExpiresAt() < new \DateTimeImmutable()) {
            $this->addFlash('error', 'Reset token has expired. Please request a new one.');
            return $this->redirectToRoute('app_forgot_password');
        }

        if ($request->isMethod('POST')) {
            $password = $request->request->get('password');
            
            if (empty($password)) {
                $this->addFlash('error', 'Password cannot be empty.');
            } else {
                $user->setPassword($passwordHasher->hashPassword($user, $password));
                $user->setResetToken(null);
                $user->setResetTokenExpiresAt(null);
                
                $entityManager->flush();
                
                $this->addFlash('success', 'Your password has been successfully reset. You can now log in.');
                return $this->redirectToRoute('app_login');
            }
        }

        return $this->render('reset_password/reset.html.twig', [
            'token' => $token
        ]);
    }
}
