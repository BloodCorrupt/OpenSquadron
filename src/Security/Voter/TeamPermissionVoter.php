<?php

namespace App\Security\Voter;

use App\Entity\Admin;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class TeamPermissionVoter extends Voter
{
    public const PERM_DASHBOARD_VIEW = 'PERM_DASHBOARD_VIEW';
    public const PERM_WHATSAPP_MANAGE = 'PERM_WHATSAPP_MANAGE';
    public const PERM_FACEBOOK_MANAGE = 'PERM_FACEBOOK_MANAGE';
    public const PERM_INSTAGRAM_MANAGE = 'PERM_INSTAGRAM_MANAGE';
    public const PERM_AI_MANAGE = 'PERM_AI_MANAGE';
    public const PERM_SUBSCRIBERS_VIEW = 'PERM_SUBSCRIBERS_VIEW';
    public const PERM_SUBSCRIBERS_MANAGE = 'PERM_SUBSCRIBERS_MANAGE';
    public const PERM_BROADCASTS_MANAGE = 'PERM_BROADCASTS_MANAGE';
    public const PERM_API_INTEGRATIONS = 'PERM_API_INTEGRATIONS';
    public const PERM_TEAM_MANAGE = 'PERM_TEAM_MANAGE';

    public static function getAvailablePermissions(): array
    {
        return [
            self::PERM_DASHBOARD_VIEW => 'View Dashboard & Analytics',
            self::PERM_WHATSAPP_MANAGE => 'Manage WhatsApp Bots & Templates',
            self::PERM_FACEBOOK_MANAGE => 'Manage Facebook Automations',
            self::PERM_INSTAGRAM_MANAGE => 'Manage Instagram Automations',
            self::PERM_AI_MANAGE => 'Manage AI Agents & Contexts',
            self::PERM_SUBSCRIBERS_VIEW => 'View Subscribers & Live Chat',
            self::PERM_SUBSCRIBERS_MANAGE => 'Reply to Live Chat & Manage Subscribers',
            self::PERM_BROADCASTS_MANAGE => 'Manage & Send Broadcasts',
            self::PERM_API_INTEGRATIONS => 'Manage HTTP APIs & Custom Fields',
            self::PERM_TEAM_MANAGE => 'Manage Team Roles & Members',
        ];
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return array_key_exists($attribute, self::getAvailablePermissions());
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof Admin) {
            return false;
        }

        $owner = $user->getParent() ?? $user;

        // If the workspace owner is a 'user' and team support is disabled, deny team management
        if ($attribute === self::PERM_TEAM_MANAGE && $owner->getAccountType() === 'user' && !$owner->isTeamEnabled()) {
            return false;
        }

        // If it's a super admin, main admin, or regular user, they have all permissions over their own account.
        if (in_array($user->getAccountType(), ['super_admin', 'admin', 'user'])) {
            return true;
        }

        // If it's a team member, check their role permissions
        if ($user->getAccountType() === 'team') {
            $role = $user->getTeamRole();
            if (!$role) {
                return false; // No role = no permissions
            }
            return $role->hasPermission($attribute);
        }

        return false;
    }
}
