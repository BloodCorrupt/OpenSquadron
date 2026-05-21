<?php

namespace App\Controller;

use App\Entity\Admin;
use App\Service\TenantDatabaseService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/accounts')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
class AccountManagementController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TenantDatabaseService $tenantDbService,
        private UserPasswordHasherInterface $passwordHasher
    ) {
    }

    #[Route('', name: 'app_accounts_index', methods: ['GET'])]
    public function index(): Response
    {
        /** @var Admin $user */
        $user = $this->getUser();

        // Security check: Only workspace Owners (Admins or Users) can manage accounts.
        // Team members do not have management access.
        if ($user->getParent() !== null) {
            throw $this->createAccessDeniedException('Team members do not have access to account management.');
        }

        // If user is a normal 'user' type and has team support disabled, deny access
        if ($user->getAccountType() === 'user' && !$user->isTeamEnabled()) {
            throw $this->createAccessDeniedException('Team support is disabled for your account.');
        }

        $accounts = [];
        if ($user->getAccountType() === 'admin') {
            // Admins can see all accounts in the system
            $accounts = $this->entityManager->getRepository(Admin::class)->findAll();
        } else {
            // Users can only see their own team accounts
            $accounts = $this->entityManager->getRepository(Admin::class)->findBy(['parent' => $user]);
        }

        return $this->render('accounts/index.html.twig', [
            'accounts' => $accounts,
            'user' => $user,
        ]);
    }

    #[Route('/new', name: 'app_accounts_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        /** @var Admin $currentUser */
        $currentUser = $this->getUser();

        if ($currentUser->getParent() !== null) {
            throw $this->createAccessDeniedException('Access denied.');
        }

        if ($currentUser->getAccountType() === 'user' && !$currentUser->isTeamEnabled()) {
            throw $this->createAccessDeniedException('Team support is disabled.');
        }

        $newAccount = new Admin();
        
        // Fetch eligible parent owners for admin creators
        $owners = [];
        if ($currentUser->getAccountType() === 'admin') {
            $owners = $this->entityManager->getRepository(Admin::class)->findBy(['parent' => null]);
        }

        if ($request->isMethod('POST')) {
            $email = $request->request->get('email');
            $plainPassword = $request->request->get('password');
            $accountType = $request->request->get('account_type', 'team');
            $teamEnabled = $request->request->getBoolean('team_enabled', false);
            $parentId = $request->request->get('parent_id');

            if (empty($email) || empty($plainPassword)) {
                $this->addFlash('error', 'Email and password are required.');
                return $this->render('accounts/new.html.twig', [
                    'owners' => $owners,
                    'currentUser' => $currentUser,
                ]);
            }

            // Check if email already exists
            $existing = $this->entityManager->getRepository(Admin::class)->findOneBy(['email' => $email]);
            if ($existing) {
                $this->addFlash('error', 'An account with this email already exists.');
                return $this->render('accounts/new.html.twig', [
                    'owners' => $owners,
                    'currentUser' => $currentUser,
                ]);
            }

            $newAccount->setEmail($email);
            $newAccount->setPassword($this->passwordHasher->hashPassword($newAccount, $plainPassword));

            if ($currentUser->getAccountType() === 'admin') {
                // Admin can set roles and types
                $newAccount->setAccountType($accountType);
                if ($accountType === 'admin') {
                    $newAccount->setRoles(['ROLE_ADMIN']);
                    $newAccount->setTeamEnabled(true); // Admins always support teams
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
            } else {
                // Users can only create Team members for themselves
                $newAccount->setAccountType('team');
                $newAccount->setRoles(['ROLE_USER']);
                $newAccount->setTeamEnabled(false);
                $newAccount->setParent($currentUser);
            }

            $this->entityManager->persist($newAccount);
            $this->entityManager->flush();

            $this->addFlash('success', 'Account created successfully.');

            return $this->redirectToRoute('app_accounts_index');
        }

        return $this->render('accounts/new.html.twig', [
            'owners' => $owners,
            'currentUser' => $currentUser,
        ]);
    }

    #[Route('/edit', name: 'app_accounts_edit_self', methods: ['GET', 'POST'])]
    public function editSelf(Request $request): Response
    {
        /** @var Admin $currentUser */
        $currentUser = $this->getUser();
        if (!$currentUser) {
            throw $this->createAccessDeniedException('Access denied.');
        }
        return $this->edit($request, $currentUser->getId());
    }

    #[Route('/{id}/edit', name: 'app_accounts_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, int $id): Response
    {
        /** @var Admin $currentUser */
        $currentUser = $this->getUser();

        // Team members can only edit their own profile details
        if ($currentUser->getParent() !== null && $currentUser->getId() !== $id) {
            throw $this->createAccessDeniedException('Access denied.');
        }

        $account = $this->entityManager->getRepository(Admin::class)->find($id);
        if (!$account) {
            throw $this->createNotFoundException('Account not found.');
        }

        // If accessing own page via parameterized route, redirect to clean parameter-less URL
        if ($request->attributes->get('_route') === 'app_accounts_edit' && $currentUser->getId() === $account->getId()) {
            return $this->redirectToRoute('app_accounts_edit_self');
        }

        // Security check:
        // 1. Admins can edit anyone.
        // 2. Any owner can edit their own profile details ($account->getId() === $currentUser->getId()).
        // 3. Owners can edit their own team members ($account->getParent() === $currentUser).
        if ($currentUser->getAccountType() !== 'admin'
            && $account->getId() !== $currentUser->getId()
            && $account->getParent() !== $currentUser
        ) {
            throw $this->createAccessDeniedException('Access denied.');
        }

        $owners = [];
        if ($currentUser->getAccountType() === 'admin') {
            $owners = $this->entityManager->getRepository(Admin::class)->findBy(['parent' => null]);
        }

        if ($request->isMethod('POST')) {
            $email = $request->request->get('email');
            $plainPassword = $request->request->get('password');
            $teamEnabled = $request->request->getBoolean('team_enabled', false);
            $parentId = $request->request->get('parent_id');
            $accountType = $request->request->get('account_type');

            $name = $request->request->get('name');
            $avatarPreset = $request->request->get('avatar_preset');
            $avatarFile = $request->files->get('avatar_file');

            if (empty($email)) {
                $this->addFlash('error', 'Email is required.');
                return $this->render('accounts/edit.html.twig', [
                    'account' => $account,
                    'owners' => $owners,
                    'currentUser' => $currentUser,
                ]);
            }

            // Check if email already taken by someone else
            $existing = $this->entityManager->getRepository(Admin::class)->findOneBy(['email' => $email]);
            if ($existing && $existing->getId() !== $account->getId()) {
                $this->addFlash('error', 'Email already in use.');
                return $this->render('accounts/edit.html.twig', [
                    'account' => $account,
                    'owners' => $owners,
                    'currentUser' => $currentUser,
                ]);
            }

            $account->setEmail($email);
            $account->setName($name);

            if ($avatarFile) {
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

            if ($currentUser->getAccountType() === 'admin') {
                if ($accountType !== null) {
                    $account->setAccountType($accountType);
                    if ($accountType === 'admin') {
                        $account->setRoles(['ROLE_ADMIN']);
                        $account->setParent(null);
                    } elseif ($accountType === 'user') {
                        $account->setRoles(['ROLE_USER']);
                        $account->setParent(null);
                    } else { // team
                        $account->setRoles(['ROLE_USER']);
                        if ($parentId) {
                            $parentObj = $this->entityManager->getRepository(Admin::class)->find($parentId);
                            $account->setParent($parentObj);
                        }
                    }
                }
                
                if ($account->getAccountType() === 'user') {
                    $account->setTeamEnabled($teamEnabled);
                }
            } else {
                // Users can only toggle parent (implied as self)
                if ($account->getAccountType() === 'user') {
                    $account->setTeamEnabled($teamEnabled);
                }
            }

            $this->entityManager->flush();
            $this->addFlash('success', 'Account updated successfully.');

            if ($currentUser->getId() === $account->getId() || $currentUser->getParent() !== null) {
                return $this->redirectToRoute('app_accounts_edit_self');
            }

            return $this->redirectToRoute('app_accounts_index');
        }

        return $this->render('accounts/edit.html.twig', [
            'account' => $account,
            'owners' => $owners,
            'currentUser' => $currentUser,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_accounts_delete', methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        /** @var Admin $currentUser */
        $currentUser = $this->getUser();

        if ($currentUser->getParent() !== null) {
            throw $this->createAccessDeniedException('Access denied.');
        }

        $account = $this->entityManager->getRepository(Admin::class)->find($id);
        if (!$account) {
            throw $this->createNotFoundException('Account not found.');
        }

        // Prevent self-deletion
        if ($account->getId() === $currentUser->getId()) {
            $this->addFlash('error', 'You cannot delete your own account.');
            return $this->redirectToRoute('app_accounts_index');
        }

        // Security check
        if ($currentUser->getAccountType() !== 'admin' && $account->getParent() !== $currentUser) {
            throw $this->createAccessDeniedException('Access denied.');
        }

        // Delete children (team members) associated with this workspace
        foreach ($account->getChildren() as $child) {
            $this->entityManager->remove($child);
        }

        $this->entityManager->remove($account);
        $this->entityManager->flush();

        $this->addFlash('success', 'Account and associated data deleted successfully.');

        return $this->redirectToRoute('app_accounts_index');
    }
}
