<?php

namespace App\Controller;

use App\Entity\Admin;
use App\Service\TenantDatabaseService;
use App\Service\SmtpMailerService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/reseller/clients')]
#[IsGranted('ROLE_ADMIN')]
class ResellerClientController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TenantDatabaseService $tenantDbService,
        private UserPasswordHasherInterface $passwordHasher,
        private SmtpMailerService $smtpMailer
    ) {
    }

    #[Route('', name: 'app_reseller_clients_index', methods: ['GET'])]
    public function index(): Response
    {
        /** @var Admin $user */
        $user = $this->getUser();

        if ($user->getAccountType() !== 'admin') {
            throw $this->createAccessDeniedException('Only Resellers can access Client Management.');
        }

        $createdAccounts = $this->entityManager->getRepository(Admin::class)->findBy(['createdBy' => $user]);
        $teamMembers = $this->entityManager->getRepository(Admin::class)->findBy(['parent' => $user]);
        $accounts = array_merge($createdAccounts, $teamMembers);
        $accounts = array_unique($accounts, SORT_REGULAR);

        return $this->render('reseller_clients/index.html.twig', [
            'accounts' => $accounts,
            'user' => $user,
        ]);
    }

    #[Route('/new', name: 'app_reseller_clients_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        /** @var Admin $currentUser */
        $currentUser = $this->getUser();

        if ($currentUser->getAccountType() !== 'admin') {
            throw $this->createAccessDeniedException('Only Resellers can create clients here.');
        }

        $newAccount = new Admin();
        
        $createdUsers = $this->entityManager->getRepository(Admin::class)->findBy(['createdBy' => $currentUser]);
        $owners = array_merge([$currentUser], $createdUsers);
        $packages = $this->entityManager->getRepository(\App\Entity\SubscriptionPackage::class)->findBy(['owner' => $currentUser]);

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
                return $this->render('reseller_clients/new.html.twig', [
                    'owners' => $owners,
                    'packages' => $packages,
                    'currentUser' => $currentUser,
                ]);
            }

            $existing = $this->entityManager->getRepository(Admin::class)->findOneBy(['email' => $email]);
            if ($existing) {
                $this->addFlash('error', 'An account with this email already exists.');
                return $this->render('reseller_clients/new.html.twig', [
                    'owners' => $owners,
                    'packages' => $packages,
                    'currentUser' => $currentUser,
                ]);
            }

            $newAccount->setEmail($email);
            $newAccount->setPassword($this->passwordHasher->hashPassword($newAccount, $plainPassword));

            $newAccount->setCreatedBy($currentUser);
            if ($accountType === 'user') {
                $newAccount->setAccountType('user');
                $newAccount->setRoles(['ROLE_USER']);
                $newAccount->setTeamEnabled($teamEnabled);
                $newAccount->setParent(null);
            } else { // team
                $newAccount->setAccountType('team');
                $newAccount->setRoles(['ROLE_USER']);
                $newAccount->setTeamEnabled(false);
                if ($parentId) {
                    $parentObj = $this->entityManager->getRepository(Admin::class)->find($parentId);
                    if ($parentObj && ($parentObj->getId() === $currentUser->getId() || $parentObj->getCreatedBy() === $currentUser)) {
                        $newAccount->setParent($parentObj);
                    } else {
                        $newAccount->setParent($currentUser);
                    }
                } else {
                    $newAccount->setParent($currentUser);
                }
            }

            if ($packageId) {
                $package = $this->entityManager->getRepository(\App\Entity\SubscriptionPackage::class)->find($packageId);
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
                        $newAccount->setAccountType('admin'); // 'admin' acts as Reseller in OpenSquadron
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
                $this->addFlash('success', 'Client account created and welcome email sent successfully.');
            } catch (\Exception $e) {
                $this->addFlash('warning', 'Client account created, but failed to send email: ' . $e->getMessage());
            }

            return $this->redirectToRoute('app_reseller_clients_index');
        }

        return $this->render('reseller_clients/new.html.twig', [
            'owners' => $owners,
            'packages' => $packages,
            'currentUser' => $currentUser,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_reseller_clients_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, int $id, \App\Service\R2SettingsService $r2SettingsService): Response
    {
        /** @var Admin $currentUser */
        $currentUser = $this->getUser();

        if ($currentUser->getAccountType() !== 'admin') {
            throw $this->createAccessDeniedException('Access denied.');
        }

        $account = $this->entityManager->getRepository(Admin::class)->find($id);
        if (!$account) {
            throw $this->createNotFoundException('Account not found.');
        }

        $canEdit = false;
        if ($account->getCreatedBy() === $currentUser) {
            $canEdit = true;
        } elseif ($account->getParent() === $currentUser) {
            $canEdit = true;
        } elseif ($account->getParent() !== null && $account->getParent()->getCreatedBy() === $currentUser) {
            $canEdit = true;
        }

        if (!$canEdit) {
            throw $this->createAccessDeniedException('Access denied.');
        }

        $createdUsers = $this->entityManager->getRepository(Admin::class)->findBy(['createdBy' => $currentUser]);
        $owners = array_merge([$currentUser], $createdUsers);
        $packages = $this->entityManager->getRepository(\App\Entity\SubscriptionPackage::class)->findBy(['owner' => $currentUser]);

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
                return $this->render('reseller_clients/edit.html.twig', [
                    'account' => $account,
                    'owners' => $owners,
                    'packages' => $packages,
                    'currentUser' => $currentUser,
                ]);
            }

            $existing = $this->entityManager->getRepository(Admin::class)->findOneBy(['email' => $email]);
            if ($existing && $existing->getId() !== $account->getId()) {
                $this->addFlash('error', 'Email already in use.');
                return $this->render('reseller_clients/edit.html.twig', [
                    'account' => $account,
                    'owners' => $owners,
                    'packages' => $packages,
                    'currentUser' => $currentUser,
                ]);
            }

            $account->setEmail($email);
            $account->setName($name);

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

            if ($accountType === 'user' || $accountType === 'team') {
                $account->setAccountType($accountType);
                if ($accountType === 'user') {
                    $account->setRoles(['ROLE_USER']);
                    $account->setParent(null);
                    $account->setTeamEnabled($teamEnabled);
                } else { // team
                    $account->setRoles(['ROLE_USER']);
                    $account->setTeamEnabled(false);
                    if ($parentId) {
                        $parentObj = $this->entityManager->getRepository(Admin::class)->find($parentId);
                        if ($parentObj && ($parentObj->getId() === $currentUser->getId() || $parentObj->getCreatedBy() === $currentUser)) {
                            $account->setParent($parentObj);
                        }
                    }
                }
            } elseif ($account->getAccountType() === 'user') {
                $account->setTeamEnabled($teamEnabled);
            }

            if ($packageId) {
                $package = $this->entityManager->getRepository(\App\Entity\SubscriptionPackage::class)->find($packageId);
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
                        $account->setParent(null);
                        $account->setTeamEnabled(true);
                    }
                }
            } else if ($request->request->has('subscription_package_id') && empty($packageId)) {
                $account->setSubscriptionPackage(null);
                $account->setSubscriptionExpiresAt(null);
            }

            $this->entityManager->flush();
            $this->addFlash('success', 'Client updated successfully.');

            return $this->redirectToRoute('app_reseller_clients_index');
        }

        return $this->render('reseller_clients/edit.html.twig', [
            'account' => $account,
            'owners' => $owners,
            'packages' => $packages,
            'currentUser' => $currentUser,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_reseller_clients_delete', methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        /** @var Admin $currentUser */
        $currentUser = $this->getUser();

        if ($currentUser->getAccountType() !== 'admin') {
            throw $this->createAccessDeniedException('Access denied.');
        }

        $account = $this->entityManager->getRepository(Admin::class)->find($id);
        if (!$account) {
            throw $this->createNotFoundException('Account not found.');
        }

        $canDelete = false;
        if ($account->getParent() === $currentUser) {
            $canDelete = true;
        } elseif ($account->getCreatedBy() === $currentUser) {
            $canDelete = true;
        }

        if (!$canDelete) {
            throw $this->createAccessDeniedException('Access denied.');
        }

        foreach ($account->getChildren() as $child) {
            $this->entityManager->remove($child);
        }

        $this->entityManager->remove($account);
        $this->entityManager->flush();

        $this->addFlash('success', 'Client and associated data deleted successfully.');

        return $this->redirectToRoute('app_reseller_clients_index');
    }
}
