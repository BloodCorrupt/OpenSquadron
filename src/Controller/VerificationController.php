<?php

namespace App\Controller;

use App\Entity\Admin;
use App\Service\SmtpMailerService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class VerificationController extends AbstractController
{
    #[Route('/verify', name: 'app_verify', methods: ['GET', 'POST'])]
    public function verify(Request $request, EntityManagerInterface $entityManager): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        if ($request->isMethod('POST')) {
            $email = $request->request->get('email');
            $code = $request->request->get('code');

            $user = $entityManager->getRepository(Admin::class)->findOneBy(['email' => $email]);

            if (!$user) {
                $this->addFlash('error', 'Account not found.');
            } elseif ($user->isVerified()) {
                $this->addFlash('success', 'Your account is already verified.');
                return $this->redirectToRoute('app_login');
            } elseif ($user->getVerificationExpiresAt() !== null && $user->getVerificationExpiresAt() < new \DateTime()) {
                // Code expired, remove user
                $entityManager->remove($user);
                $entityManager->flush();
                $this->addFlash('error', 'Verification code has expired. Your account has been removed. Please register again.');
                // We don't want to redirect to login because they can't login.
                // We'll let it just show the error on the verify page, or redirect to register if there is a register page.
            } elseif ($user->getVerificationCode() !== $code) {
                $this->addFlash('error', 'Invalid verification code.');
            } else {
                $user->setIsVerified(true);
                $user->setVerificationCode(null);
                $user->setVerificationExpiresAt(null);
                $entityManager->flush();

                $this->addFlash('success', 'Your account has been verified! You can now log in.');
                return $this->redirectToRoute('app_login');
            }
        }

        return $this->render('verification/index.html.twig');
    }

    #[Route('/verify/resend', name: 'app_verify_resend', methods: ['POST'])]
    public function resend(Request $request, EntityManagerInterface $entityManager, SmtpMailerService $mailerService): Response
    {
        $email = $request->request->get('email');
        $user = $entityManager->getRepository(Admin::class)->findOneBy(['email' => $email]);

        if (!$user) {
            $this->addFlash('error', 'Account not found.');
            return $this->redirectToRoute('app_verify');
        }

        if ($user->isVerified()) {
            $this->addFlash('success', 'Your account is already verified.');
            return $this->redirectToRoute('app_login');
        }

        // Generate new code
        $plainPassword = '...'; // We don't have the plain password anymore. The welcome email requires plainPassword. 
        // Wait, the original welcome email expects plainPassword. If we resend, we don't have it.
        // Let's create a separate email template for "Resend Verification Code" or just pass null.
        // For now, let's just pass '******** (Hidden)' for the password.
        
        $user->setVerificationCode(str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT));
        $user->setVerificationExpiresAt((new \DateTime())->modify('+5 minutes'));
        $entityManager->flush();

        try {
            $mailerService->sendWelcomeEmail($user, '******** (Hidden)', $user->getOwner() ?? clone $user);
            $this->addFlash('success', 'A new verification code has been sent to your email.');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to send email. Please check SMTP settings.');
        }

        return $this->redirectToRoute('app_verify');
    }
}
