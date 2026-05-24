<?php

namespace App\Controller;

use App\Entity\Admin;
use App\Entity\TeamRole;
use App\Security\Voter\TeamPermissionVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(TeamPermissionVoter::PERM_TEAM_MANAGE)]
class TeamManagerController extends AbstractController
{
    private function checkTeamEnabled(): void
    {
        /** @var Admin $user */
        $user = $this->getUser();
        // Super admins and admins can always manage teams
        if (in_array($user->getAccountType(), ['super_admin', 'admin'])) {
            return;
        }
        if (!$user->isTeamEnabled()) {
            throw $this->createAccessDeniedException('Team management is not enabled for your account.');
        }
    }

    private function getOwner(): Admin
    {
        /** @var Admin $user */
        $user = $this->getUser();
        // If the user is a team member, their owner is their parent
        if ($user->getAccountType() === 'team') {
            return $user->getParent() ?? $user;
        }
        return $user;
    }

    #[Route('/team-manager/roles', name: 'app_team_roles', methods: ['GET'])]
    public function roles(EntityManagerInterface $em): Response
    {
        $this->checkTeamEnabled();
        $owner = $this->getOwner();

        $roles = $em->getRepository(TeamRole::class)->findBy(['owner' => $owner], ['id' => 'DESC']);
        $availablePermissions = TeamPermissionVoter::getAvailablePermissions();

        return $this->render('team_manager/roles.html.twig', [
            'roles' => $roles,
            'availablePermissions' => $availablePermissions,
        ]);
    }

    #[Route('/team-manager/roles/save', name: 'app_team_roles_save', methods: ['POST'])]
    public function saveRole(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $this->checkTeamEnabled();
        $owner = $this->getOwner();

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid payload'], 400);
        }

        $id = $payload['id'] ?? null;
        $name = trim($payload['name'] ?? '');
        $permissions = $payload['permissions'] ?? [];

        if (empty($name)) {
            return new JsonResponse(['success' => false, 'error' => 'Role name is required.'], 400);
        }

        if ($id) {
            $role = $em->getRepository(TeamRole::class)->findOneBy(['id' => $id, 'owner' => $owner]);
            if (!$role) {
                return new JsonResponse(['success' => false, 'error' => 'Role not found.'], 404);
            }
        } else {
            $role = new TeamRole();
            $role->setOwner($owner);
        }

        $role->setName($name);
        
        // Filter permissions to ensure they are valid
        $validPermissions = array_keys(TeamPermissionVoter::getAvailablePermissions());
        $filteredPermissions = array_values(array_intersect($permissions, $validPermissions));
        $role->setPermissions($filteredPermissions);

        $em->persist($role);
        $em->flush();

        return new JsonResponse(['success' => true]);
    }

    #[Route('/team-manager/roles/delete', name: 'app_team_roles_delete', methods: ['POST'])]
    public function deleteRole(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $this->checkTeamEnabled();
        $owner = $this->getOwner();

        $payload = json_decode($request->getContent(), true);
        $id = $payload['id'] ?? null;

        $role = $em->getRepository(TeamRole::class)->findOneBy(['id' => $id, 'owner' => $owner]);
        if (!$role) {
            return new JsonResponse(['success' => false, 'error' => 'Role not found.'], 404);
        }

        $em->remove($role);
        $em->flush();

        return new JsonResponse(['success' => true]);
    }

    #[Route('/team-manager/members', name: 'app_team_members', methods: ['GET'])]
    public function members(EntityManagerInterface $em): Response
    {
        $this->checkTeamEnabled();
        $owner = $this->getOwner();

        $members = $em->getRepository(Admin::class)->findBy([
            'parent' => $owner,
            'accountType' => 'team'
        ], ['id' => 'DESC']);

        $roles = $em->getRepository(TeamRole::class)->findBy(['owner' => $owner], ['name' => 'ASC']);

        return $this->render('team_manager/members.html.twig', [
            'members' => $members,
            'roles' => $roles,
        ]);
    }

    #[Route('/team-manager/members/save', name: 'app_team_members_save', methods: ['POST'])]
    public function saveMember(Request $request, EntityManagerInterface $em, UserPasswordHasherInterface $passwordHasher): JsonResponse
    {
        $this->checkTeamEnabled();
        $owner = $this->getOwner();

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid payload'], 400);
        }

        $id = $payload['id'] ?? null;
        $name = trim($payload['name'] ?? '');
        $email = trim($payload['email'] ?? '');
        $password = trim($payload['password'] ?? '');
        $roleId = $payload['roleId'] ?? null;

        if (empty($email) || empty($name)) {
            return new JsonResponse(['success' => false, 'error' => 'Name and Email are required.'], 400);
        }

        $role = null;
        if ($roleId) {
            $role = $em->getRepository(TeamRole::class)->findOneBy(['id' => $roleId, 'owner' => $owner]);
            if (!$role) {
                return new JsonResponse(['success' => false, 'error' => 'Invalid role selected.'], 400);
            }
        }

        if ($id) {
            $member = $em->getRepository(Admin::class)->findOneBy(['id' => $id, 'parent' => $owner, 'accountType' => 'team']);
            if (!$member) {
                return new JsonResponse(['success' => false, 'error' => 'Team member not found.'], 404);
            }
        } else {
            if (empty($password)) {
                return new JsonResponse(['success' => false, 'error' => 'Password is required for new members.'], 400);
            }
            
            // Check if email already exists
            $existing = $em->getRepository(Admin::class)->findOneBy(['email' => $email]);
            if ($existing) {
                return new JsonResponse(['success' => false, 'error' => 'A user with this email already exists.'], 400);
            }

            $member = new Admin();
            $member->setParent($owner);
            $member->setAccountType('team');
            $member->setRoles(['ROLE_USER']);
        }

        $member->setName($name);
        $member->setEmail($email);
        $member->setTeamRole($role);

        if (!empty($password)) {
            $hashedPassword = $passwordHasher->hashPassword($member, $password);
            $member->setPassword($hashedPassword);
        }

        $em->persist($member);
        $em->flush();

        return new JsonResponse(['success' => true]);
    }

    #[Route('/team-manager/members/delete', name: 'app_team_members_delete', methods: ['POST'])]
    public function deleteMember(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $this->checkTeamEnabled();
        $owner = $this->getOwner();

        $payload = json_decode($request->getContent(), true);
        $id = $payload['id'] ?? null;

        $member = $em->getRepository(Admin::class)->findOneBy(['id' => $id, 'parent' => $owner, 'accountType' => 'team']);
        if (!$member) {
            return new JsonResponse(['success' => false, 'error' => 'Team member not found.'], 404);
        }

        $em->remove($member);
        $em->flush();

        return new JsonResponse(['success' => true]);
    }
}
