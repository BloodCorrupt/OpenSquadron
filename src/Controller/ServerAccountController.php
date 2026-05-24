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

#[Route('/server/accounts')]
#[IsGranted('ROLE_ADMIN')]
class ServerAccountController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TenantDatabaseService $tenantDbService,
        private UserPasswordHasherInterface $passwordHasher
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

        if ($request->isMethod('POST')) {
            $email = $request->request->get('email');
            $plainPassword = $request->request->get('password');
            $accountType = $request->request->get('account_type', 'team');
            $teamEnabled = $request->request->getBoolean('team_enabled', false);
            $parentId = $request->request->get('parent_id');

            if (empty($email) || empty($plainPassword)) {
                $this->addFlash('error', 'Email and password are required.');
                return $this->render('server_accounts/new.html.twig', [
                    'owners' => $owners,
                    'currentUser' => $currentUser,
                ]);
            }

            // Check if email already exists
            $existing = $this->entityManager->getRepository(Admin::class)->findOneBy(['email' => $email]);
            if ($existing) {
                $this->addFlash('error', 'An account with this email already exists.');
                return $this->render('server_accounts/new.html.twig', [
                    'owners' => $owners,
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

            $this->entityManager->persist($newAccount);
            $this->entityManager->flush();

            $this->addFlash('success', 'Account created successfully.');

            return $this->redirectToRoute('app_server_accounts_index');
        }

        return $this->render('server_accounts/new.html.twig', [
            'owners' => $owners,
            'currentUser' => $currentUser,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_server_accounts_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, int $id): Response
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
                return $this->render('server_accounts/edit.html.twig', [
                    'account' => $account,
                    'owners' => $owners,
                    'currentUser' => $currentUser,
                ]);
            }

            $existing = $this->entityManager->getRepository(Admin::class)->findOneBy(['email' => $email]);
            if ($existing && $existing->getId() !== $account->getId()) {
                $this->addFlash('error', 'Email already in use.');
                return $this->render('server_accounts/edit.html.twig', [
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

            $this->entityManager->flush();
            $this->addFlash('success', 'Account updated successfully.');

            return $this->redirectToRoute('app_server_accounts_index');
        }

        return $this->render('server_accounts/edit.html.twig', [
            'account' => $account,
            'owners' => $owners,
            'currentUser' => $currentUser,
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
}
