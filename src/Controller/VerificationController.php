<?php

namespace App\Controller;

use App\Entity\Admin;
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
            } elseif ($user->getVerificationCode() !== $code) {
                $this->addFlash('error', 'Invalid verification code.');
            } else {
                $user->setIsVerified(true);
                $user->setVerificationCode(null);
                $entityManager->flush();

                $this->addFlash('success', 'Your account has been verified! You can now log in.');
                return $this->redirectToRoute('app_login');
            }
        }

        return $this->render('verification/index.html.twig');
    }
}
