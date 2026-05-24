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

class RegistrationController extends AbstractController
{
    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        SmtpMailerService $smtpMailer
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        $refId = $request->query->get('ref');
        
        /** @var Admin|null $creator */
        $creator = null;

        // 1. Try to get the creator from the Domain Context first (Custom Domains)
        if ($request->attributes->has('_reseller_owner')) {
            $creator = $request->attributes->get('_reseller_owner');
        }

        // 2. Fallback to `?ref=` parameter (e.g. for super admin platform links)
        if (!$creator && $refId) {
            $creator = $entityManager->getRepository(Admin::class)->find($refId);
        }

        // 3. Final fallback: default to super_admin (find the first one)
        if (!$creator || !in_array($creator->getAccountType(), ['super_admin', 'admin'])) {
            $creator = $entityManager->getRepository(Admin::class)->findOneBy(['accountType' => 'super_admin']);
        }

        if (!$creator) {
            return new Response('System is not properly initialized. No Super Admin found.', 500);
        }

        // Check if registration is enabled
        if (!$creator->isRegistrationEnabled() || !$creator->getSmtpSettings()) {
            $this->addFlash('error', 'Account registration is currently disabled for this workspace.');
            return $this->render('registration/index.html.twig', [
                'registrationBlocked' => true
            ]);
        }

        if ($request->isMethod('POST')) {
            $name = $request->request->get('name');
            $email = $request->request->get('email');
            $plainPassword = $request->request->get('password');

            if (empty($name) || empty($email) || empty($plainPassword)) {
                $this->addFlash('error', 'All fields are required.');
                return $this->render('registration/index.html.twig', [
                    'registrationBlocked' => false
                ]);
            }

            $existing = $entityManager->getRepository(Admin::class)->findOneBy(['email' => $email]);
            if ($existing) {
                $this->addFlash('error', 'An account with this email already exists.');
                return $this->render('registration/index.html.twig', [
                    'registrationBlocked' => false
                ]);
            }

            $user = new Admin();
            $user->setName($name);
            $user->setEmail($email);
            $user->setPassword($passwordHasher->hashPassword($user, $plainPassword));
            
            $user->setAccountType('user');
            $user->setRoles(['ROLE_USER']);
            $user->setCreatedBy($creator);
            $user->setParent(null); // Direct client of the creator
            
            $user->setVerificationCode(str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT));
            $user->setVerificationExpiresAt((new \DateTime())->modify('+5 minutes'));
            $user->setIsVerified(false);
            
            // Allow team if they want to pay for it later, or default to false. Default to false for now.
            $user->setTeamEnabled(false);

            // Assign default subscription package if exists for this creator
            $defaultPackage = $entityManager->getRepository(\App\Entity\SubscriptionPackage::class)->findOneBy([
                'owner' => $creator,
                'isDefault' => true
            ]);

            if ($defaultPackage) {
                $user->setSubscriptionPackage($defaultPackage);
                if ($defaultPackage->isLifetime()) {
                    $user->setSubscriptionExpiresAt(null);
                } else {
                    $user->setSubscriptionExpiresAt((new \DateTime())->modify('+' . $defaultPackage->getValidityDays() . ' days'));
                }
                
                // If it's a reseller package, upgrade them instantly.
                if ($defaultPackage->isResellerPackage()) {
                    $user->setAccountType('admin');
                    $user->setParent(null);
                    $user->setTeamEnabled(true);
                }
            }

            $entityManager->persist($user);
            $entityManager->flush();

            try {
                $smtpMailer->sendWelcomeEmail($creator, $user, $plainPassword);
                $this->addFlash('success', 'Your account has been created! A verification code has been sent to your email.');
                return $this->redirectToRoute('app_verify');
            } catch (\Exception $e) {
                // If email fails, we might want to delete the user or just leave them unverified
                $this->addFlash('error', 'Account created, but we failed to send the verification email. Please contact support.');
                return $this->redirectToRoute('app_login');
            }
        }

        return $this->render('registration/index.html.twig', [
            'registrationBlocked' => false
        ]);
    }
}
