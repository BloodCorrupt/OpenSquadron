<?php

namespace App\Controller;

use App\Entity\Admin;
use App\Entity\SubscriptionPackage;
use App\Service\TenantDatabaseService;
use App\Service\SmtpMailerService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/server/accounts')]
#[IsGranted('ROLE_ADMIN')]
class ServerAccountController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TenantDatabaseService $tenantDbService,
        private UserPasswordHasherInterface $passwordHasher,
        private SmtpMailerService $smtpMailer
    ) {
    }

    #[Route('', name: 'app_server_accounts_index', methods: ['GET'])]
    public function index(): Response
    {
        /** @var Admin $user */
        $user = $this->getUser();

        if ($user->getAccountType() !== 'super_admin') {
            throw $this->createAccessDeniedException('Only Server Root Admins can access Server Account Management.');
        }

        $accounts = $this->entityManager->getRepository(Admin::class)->findAll();

        return $this->render('server_accounts/index.html.twig', [
            'accounts' => $accounts,
            'user' => $user,
        ]);
    }

    #[Route('/new', name: 'app_server_accounts_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        /** @var Admin $currentUser */
        $currentUser = $this->getUser();

        if ($currentUser->getAccountType() !== 'super_admin') {
            throw $this->createAccessDeniedException('Only Server Root Admins can create accounts here.');
        }

        $newAccount = new Admin();
        
        $owners = $this->entityManager->getRepository(Admin::class)->findBy(['parent' => null]);
        $packages = $this->entityManager->getRepository(SubscriptionPackage::class)->findBy(['owner' => $currentUser]);

        if ($request->isMethod('POST')) {
            $email = $request->request->get('email');
            $plainPassword = $request->request->get('password');
            $accountType = $request->request->get('account_type', 'team');
            $teamEnabled = $request->request->getBoolean('team_enabled', false);
            $parentId = $request->request->get('parent_id');
            $packageId = $request->request->get('subscription_package_id');
            $manualExpiryDateStr = $request->request->get('manual_expiry_date');

            if (empty($plainPassword)) {
                $plainPassword = bin2hex(random_bytes(6));
            }

            if (empty($email)) {
                $this->addFlash('error', 'Email is required.');
                return $this->render('server_accounts/new.html.twig', [
                    'owners' => $owners,
                    'packages' => $packages,
                    'currentUser' => $currentUser,
                ]);
            }

            // Check if email already exists
            $existing = $this->entityManager->getRepository(Admin::class)->findOneBy(['email' => $email]);
            if ($existing) {
                $this->addFlash('error', 'An account with this email already exists.');
                return $this->render('server_accounts/new.html.twig', [
                    'owners' => $owners,
                    'packages' => $packages,
                    'currentUser' => $currentUser,
                ]);
            }

            $newAccount->setEmail($email);
            $newAccount->setPassword($this->passwordHasher->hashPassword($newAccount, $plainPassword));

            $newAccount->setAccountType($accountType);
            $newAccount->setCreatedBy($currentUser);
            if ($accountType === 'super_admin' || $accountType === 'admin') {
                $newAccount->setRoles(['ROLE_ADMIN']);
                $newAccount->setTeamEnabled(true);
                $newAccount->setParent(null);
            } elseif ($accountType === 'user') {
                $newAccount->setRoles(['ROLE_USER']);
                $newAccount->setTeamEnabled($teamEnabled);
                $newAccount->setParent(null);
            } else { // team
                $newAccount->setRoles(['ROLE_USER']);
                $newAccount->setTeamEnabled(false);
                if ($parentId) {
                    $parentObj = $this->entityManager->getRepository(Admin::class)->find($parentId);
                    $newAccount->setParent($parentObj);
                } else {
                    $newAccount->setParent($currentUser);
                }
            }

            if ($packageId) {
                $package = $this->entityManager->getRepository(SubscriptionPackage::class)->find($packageId);
                if ($package && $package->getOwner() === $currentUser) {
                    $newAccount->setSubscriptionPackage($package);
                    
                    if (!empty($manualExpiryDateStr)) {
                        $newAccount->setSubscriptionExpiresAt(new \DateTime($manualExpiryDateStr));
                    } elseif ($package->isLifetime()) {
                        $newAccount->setSubscriptionExpiresAt(null);
                    } else {
                        $newAccount->setSubscriptionExpiresAt((new \DateTime())->modify('+' . $package->getValidityDays() . ' days'));
                    }
                    
                    if ($package->isResellerPackage()) {
                        $newAccount->setAccountType('admin');
                        $newAccount->setRoles(['ROLE_ADMIN']);
                        $newAccount->setParent(null);
                        $newAccount->setTeamEnabled(true);
                    }
                }
            }

            $newAccount->setVerificationCode(str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT));
            $newAccount->setVerificationExpiresAt((new \DateTime())->modify('+5 minutes'));
            $newAccount->setIsVerified(false);

            $this->entityManager->persist($newAccount);
            $this->entityManager->flush();

            try {
                $this->smtpMailer->sendWelcomeEmail($currentUser, $newAccount, $plainPassword);
                $this->addFlash('success', 'Account created and welcome email sent successfully.');
            } catch (\Exception $e) {
                $this->addFlash('warning', 'Account created, but failed to send email: ' . $e->getMessage());
            }

            return $this->redirectToRoute('app_server_accounts_index');
        }

        return $this->render('server_accounts/new.html.twig', [
            'owners' => $owners,
            'packages' => $packages,
            'currentUser' => $currentUser,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_server_accounts_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, int $id, \App\Service\R2SettingsService $r2SettingsService): Response
    {
        /** @var Admin $currentUser */
        $currentUser = $this->getUser();

        if ($currentUser->getAccountType() !== 'super_admin') {
            throw $this->createAccessDeniedException('Access denied.');
        }

        $account = $this->entityManager->getRepository(Admin::class)->find($id);
        if (!$account) {
            throw $this->createNotFoundException('Account not found.');
        }

        $owners = $this->entityManager->getRepository(Admin::class)->findBy(['parent' => null]);
        $packages = $this->entityManager->getRepository(SubscriptionPackage::class)->findBy(['owner' => $currentUser]);

        if ($request->isMethod('POST')) {
            $email = $request->request->get('email');
            $plainPassword = $request->request->get('password');
            $teamEnabled = $request->request->getBoolean('team_enabled', false);
            $parentId = $request->request->get('parent_id');
            $accountType = $request->request->get('account_type');
            $packageId = $request->request->get('subscription_package_id');
            $manualExpiryDateStr = $request->request->get('manual_expiry_date');

            $name = $request->request->get('name');
            $avatarPreset = $request->request->get('avatar_preset');
            $avatarUrl = $request->request->get('avatar_url');
            $avatarFile = $request->files->get('avatar_file');

            if (empty($email)) {
                $this->addFlash('error', 'Email is required.');
                return $this->render('server_accounts/edit.html.twig', [
                    'account' => $account,
                    'owners' => $owners,
                    'packages' => $packages,
                    'currentUser' => $currentUser,
                    'timezones' => $this->getTimezonesList(),
                ]);
            }

            $existing = $this->entityManager->getRepository(Admin::class)->findOneBy(['email' => $email]);
            if ($existing && $existing->getId() !== $account->getId()) {
                $this->addFlash('error', 'Email already in use.');
                return $this->render('server_accounts/edit.html.twig', [
                    'account' => $account,
                    'owners' => $owners,
                    'packages' => $packages,
                    'currentUser' => $currentUser,
                    'timezones' => $this->getTimezonesList(),
                ]);
            }

            $account->setEmail($email);
            $account->setName($name);

            $timezone = $request->request->get('timezone');
            if ($timezone !== null && $timezone !== '') {
                $account->setTimezone($timezone);
            } else {
                $account->setTimezone('UTC');
            }

            $settings = $r2SettingsService->getActiveSettings($account);
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
                    $account->setAvatar('uploads/avatars/'.$newFilename);
                } catch (\Exception $e) {
                    $this->addFlash('error', 'Failed to upload avatar: '.$e->getMessage());
                }
            } elseif (!empty($avatarUrl)) {
                $account->setAvatar($avatarUrl);
            } elseif (!empty($avatarPreset)) {
                if ($avatarPreset === 'clear') {
                    $account->setAvatar(null);
                } else {
                    $account->setAvatar('preset:'.$avatarPreset);
                }
            }

            if (!empty($plainPassword)) {
                $account->setPassword($this->passwordHasher->hashPassword($account, $plainPassword));
            }

            if ($accountType !== null && $accountType !== '') {
                $account->setAccountType($accountType);
                if ($accountType === 'super_admin' || $accountType === 'admin') {
                    $account->setRoles(['ROLE_ADMIN']);
                    $account->setParent(null);
                } elseif ($accountType === 'user') {
                    $account->setRoles(['ROLE_USER']);
                    $account->setParent(null);
                } else { // team
                    $account->setRoles(['ROLE_USER']);
                    if ($parentId) {
                        $parentObj = $this->entityManager->getRepository(Admin::class)->find($parentId);
                        if ($parentObj) {
                            $account->setParent($parentObj);
                        }
                    }
                }
            }
            
            if (in_array($account->getAccountType(), ['user', 'admin', 'super_admin'])) {
                $account->setTeamEnabled($teamEnabled);
            }

            if ($packageId) {
                $package = $this->entityManager->getRepository(SubscriptionPackage::class)->find($packageId);
                if ($package && $package->getOwner() === $currentUser) {
                    $account->setSubscriptionPackage($package);
                    
                    if (!empty($manualExpiryDateStr)) {
                        $account->setSubscriptionExpiresAt(new \DateTime($manualExpiryDateStr));
                    } elseif ($package->isLifetime()) {
                        $account->setSubscriptionExpiresAt(null);
                    } else {
                        $account->setSubscriptionExpiresAt((new \DateTime())->modify('+' . $package->getValidityDays() . ' days'));
                    }
                    
                    if ($package->isResellerPackage()) {
                        $account->setAccountType('admin');
                        $account->setRoles(['ROLE_ADMIN']);
                        $account->setParent(null);
                        $account->setTeamEnabled(true);
                    }
                }
            } else if ($request->request->has('subscription_package_id') && empty($packageId)) {
                $account->setSubscriptionPackage(null);
                $account->setSubscriptionExpiresAt(null);
            }

            $this->entityManager->flush();
            $this->addFlash('success', 'Account updated successfully.');

            return $this->redirectToRoute('app_server_accounts_index');
        }

        return $this->render('server_accounts/edit.html.twig', [
            'account' => $account,
            'owners' => $owners,
            'packages' => $packages,
            'currentUser' => $currentUser,
            'timezones' => $this->getTimezonesList(),
        ]);
    }

    #[Route('/{id}/delete', name: 'app_server_accounts_delete', methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        /** @var Admin $currentUser */
        $currentUser = $this->getUser();

        if ($currentUser->getAccountType() !== 'super_admin') {
            throw $this->createAccessDeniedException('Access denied.');
        }

        $account = $this->entityManager->getRepository(Admin::class)->find($id);
        if (!$account) {
            throw $this->createNotFoundException('Account not found.');
        }

        if ($account->getId() === $currentUser->getId()) {
            $this->addFlash('error', 'You cannot delete your own account.');
            return $this->redirectToRoute('app_server_accounts_index');
        }

        foreach ($account->getChildren() as $child) {
            $this->entityManager->remove($child);
        }

        $this->entityManager->remove($account);
        $this->entityManager->flush();

        $this->addFlash('success', 'Account and associated data deleted successfully.');

        return $this->redirectToRoute('app_server_accounts_index');
    }

    private function getTimezonesList(): array
    {
        return [
            'UTC' => 'UTC (Default)',
            '-12:00' => '(UTC-12:00) International Date Line West',
            '-11:00' => '(UTC-11:00) Coordinated Universal Time-11',
            '-10:00' => '(UTC-10:00) Hawaii',
            '-09:00' => '(UTC-09:00) Alaska',
            '-08:00' => '(UTC-08:00) Pacific Time (US & Canada)',
            '-07:00' => '(UTC-07:00) Mountain Time (US & Canada)',
            '-06:00' => '(UTC-06:00) Central Time (US & Canada)',
            '-05:00' => '(UTC-05:00) Eastern Time (US & Canada)',
            '-04:00' => '(UTC-04:00) Atlantic Time (Canada)',
            '-03:30' => '(UTC-03:30) Newfoundland',
            '-03:00' => '(UTC-03:00) Brasilia, Buenos Aires',
            '-02:00' => '(UTC-02:00) Mid-Atlantic',
            '-01:00' => '(UTC-01:00) Azores, Cape Verde Is.',
            '+00:00' => '(UTC+00:00) London, Dublin, Lisbon',
            '+01:00' => '(UTC+01:00) Paris, Berlin, Rome, Madrid',
            '+02:00' => '(UTC+02:00) Cairo, Athens, Jerusalem',
            '+03:00' => '(UTC+03:00) Moscow, Istanbul, Riyadh',
            '+03:30' => '(UTC+03:30) Tehran',
            '+04:00' => '(UTC+04:00) Dubai, Baku',
            '+04:30' => '(UTC+04:30) Kabul',
            '+05:00' => '(UTC+05:00) Karachi, Tashkent',
            '+05:30' => '(UTC+05:30) Chennai, Kolkata, Mumbai, New Delhi',
            '+05:45' => '(UTC+05:45) Kathmandu',
            '+06:00' => '(UTC+06:00) Almaty, Dhaka',
            '+06:30' => '(UTC+06:30) Yangon (Rangoon)',
            '+07:00' => '(UTC+07:00) Bangkok, Hanoi, Jakarta',
            '+08:00' => '(UTC+08:00) Beijing, Singapore, Perth',
            '+09:00' => '(UTC+09:00) Tokyo, Seoul, Osaka',
            '+09:30' => '(UTC+09:30) Adelaide, Darwin',
            '+10:00' => '(UTC+10:00) Sydney, Melbourne, Brisbane',
            '+11:00' => '(UTC+11:00) Solomon Is., New Caledonia',
            '+12:00' => '(UTC+12:00) Auckland, Wellington',
            '+13:00' => '(UTC+13:00) Nuku\'alofa'
        ];
    }
}
