<?php

namespace App\Service;

use App\Entity\Admin;
use App\Entity\WhatsAppConnection;
use App\Entity\FacebookConnection;
use App\Entity\InstagramConnection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Centralized service for checking and enforcing subscription package limits.
 */
class SubscriptionUsageService
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * Returns the active owner (resolves team members to their parent).
     */
    private function resolveOwner(Admin $user): Admin
    {
        if ($user->getAccountType() === 'team' && $user->getParent()) {
            return $user->getParent();
        }
        return $user;
    }

    /**
     * Checks whether the given user's subscription is currently active.
     * Super admins and admins with no package are treated as unlimited.
     */
    public function isSubscriptionActive(Admin $user): bool
    {
        $owner = $this->resolveOwner($user);

        // Super admins are always active.
        if ($owner->getAccountType() === 'super_admin') {
            return true;
        }

        $package = $owner->getSubscriptionPackage();

        // If no package is assigned, we treat this as active (no limits enforced).
        if (!$package) {
            return true;
        }

        // Lifetime packages never expire.
        if ($package->isLifetime()) {
            return true;
        }

        $expiresAt = $owner->getSubscriptionExpiresAt();
        if ($expiresAt === null) {
            return true;
        }

        return new \DateTime() < $expiresAt;
    }

    /**
     * Returns the feature limits array from the active package, or null if none.
     */
    private function getLimits(Admin $user): ?array
    {
        $owner = $this->resolveOwner($user);
        $package = $owner->getSubscriptionPackage();
        if (!$package) {
            return null;
        }
        return $package->getFeatures()['limits'] ?? null;
    }

    /**
     * Returns the allowed modules array from the active package, or null if none.
     */
    private function getAllowedModules(Admin $user): ?array
    {
        $owner = $this->resolveOwner($user);
        $package = $owner->getSubscriptionPackage();
        if (!$package) {
            return null;
        }
        return $package->getFeatures()['modules'] ?? null;
    }

    /**
     * Returns true if the user's package grants access to the given module.
     * If the user has no package or no modules are restricted, access is always granted.
     * Module keys: 'whatsapp', 'facebook', 'ai_copilot', 'ecommerce'
     */
    public function hasModuleAccess(Admin $user, string $module): bool
    {
        $owner = $this->resolveOwner($user);

        // Super admins have unrestricted access.
        if ($owner->getAccountType() === 'super_admin') {
            return true;
        }

        $modules = $this->getAllowedModules($owner);

        // No package or no modules configured = unrestricted.
        if ($modules === null) {
            return true;
        }

        return in_array($module, $modules, true);
    }

    /**
     * Returns true if the user can add another WhatsApp or Facebook bot connection.
     * A limit of 0 means unlimited.
     */
    public function canAddBot(Admin $user): bool
    {
        $owner = $this->resolveOwner($user);

        if ($owner->getAccountType() === 'super_admin') {
            return true;
        }

        $limits = $this->getLimits($owner);
        if ($limits === null) {
            return true;
        }

        $limit = (int) ($limits['bots'] ?? 0);
        if ($limit === 0) {
            return true; // 0 = unlimited
        }

        // Count all bot connections for this owner
        $whatsappCount = $this->em->getRepository(WhatsAppConnection::class)->count(['owner' => $owner]);
        $facebookCount = $this->em->getRepository(FacebookConnection::class)->count(['owner' => $owner]);
        $instagramCount = $this->em->getRepository(InstagramConnection::class)->count(['owner' => $owner]);
        $totalBots = $whatsappCount + $facebookCount + $instagramCount;

        return $totalBots < $limit;
    }

    /**
     * Returns the current bot usage info for the given user.
     * Returns ['current' => int, 'limit' => int] where limit 0 = unlimited.
     */
    public function getBotUsage(Admin $user): array
    {
        $owner = $this->resolveOwner($user);
        $limits = $this->getLimits($owner);
        $limit = (int) ($limits['bots'] ?? 0);

        $whatsappCount = $this->em->getRepository(WhatsAppConnection::class)->count(['owner' => $owner]);
        $facebookCount = $this->em->getRepository(FacebookConnection::class)->count(['owner' => $owner]);
        $instagramCount = $this->em->getRepository(InstagramConnection::class)->count(['owner' => $owner]);

        return [
            'current' => $whatsappCount + $facebookCount + $instagramCount,
            'limit'   => $limit,
        ];
    }

    /**
     * Returns true if the user can add another team member.
     * A limit of 0 means unlimited.
     */
    public function canAddTeamMember(Admin $user): bool
    {
        $owner = $this->resolveOwner($user);

        if ($owner->getAccountType() === 'super_admin') {
            return true;
        }

        $limits = $this->getLimits($owner);
        if ($limits === null) {
            return true;
        }

        $limit = (int) ($limits['team'] ?? 0);
        if ($limit === 0) {
            return true; // 0 = unlimited
        }

        $currentTeamCount = $this->em->getRepository(Admin::class)->count([
            'parent'      => $owner,
            'accountType' => 'team',
        ]);

        return $currentTeamCount < $limit;
    }

    /**
     * Returns the current team member usage info.
     * Returns ['current' => int, 'limit' => int] where limit 0 = unlimited.
     */
    public function getTeamUsage(Admin $user): array
    {
        $owner = $this->resolveOwner($user);
        $limits = $this->getLimits($owner);
        $limit = (int) ($limits['team'] ?? 0);

        $current = $this->em->getRepository(Admin::class)->count([
            'parent'      => $owner,
            'accountType' => 'team',
        ]);

        return [
            'current' => $current,
            'limit'   => $limit,
        ];
    }

    /**
     * Returns true if the user can still send messages this month.
     * A limit of 0 means unlimited.
     */
    public function canSendMessage(Admin $user): bool
    {
        $owner = $this->resolveOwner($user);

        if ($owner->getAccountType() === 'super_admin') {
            return true;
        }

        $limits = $this->getLimits($owner);
        if ($limits === null) {
            return true;
        }

        $limit = (int) ($limits['messages'] ?? 0);
        if ($limit === 0) {
            return true; // 0 = unlimited
        }

        // Reset monthly counter if month has changed
        $this->maybeResetMessageCount($owner);

        return $owner->getMonthlyMessageCount() < $limit;
    }

    /**
     * Increments the monthly message counter for this user.
     * Call this after a message is successfully sent.
     */
    public function incrementMessageCount(Admin $user): void
    {
        $owner = $this->resolveOwner($user);

        $limits = $this->getLimits($owner);
        if ($limits === null) {
            return;
        }

        $limit = (int) ($limits['messages'] ?? 0);
        if ($limit === 0) {
            return; // No limit — no need to track
        }

        $this->maybeResetMessageCount($owner);
        $owner->setMonthlyMessageCount($owner->getMonthlyMessageCount() + 1);
        $this->em->flush();
    }

    /**
     * Resets the monthly message counter if the current month differs from the last reset date.
     */
    private function maybeResetMessageCount(Admin $user): void
    {
        $now = new \DateTime();
        $lastReset = $user->getLastMessageResetDate();

        if ($lastReset === null || $lastReset->format('Y-m') !== $now->format('Y-m')) {
            $user->setMonthlyMessageCount(0);
            $user->setLastMessageResetDate($now);
            $this->em->flush();
        }
    }

    /**
     * Returns the current message usage info.
     * Returns ['current' => int, 'limit' => int] where limit 0 = unlimited.
     */
    public function getMessageUsage(Admin $user): array
    {
        $owner = $this->resolveOwner($user);

        $this->maybeResetMessageCount($owner);

        $limits = $this->getLimits($owner);
        $limit = (int) ($limits['messages'] ?? 0);

        return [
            'current' => $owner->getMonthlyMessageCount(),
            'limit'   => $limit,
        ];
    }

    /**
     * Returns true if the user can add another eCommerce product.
     * A limit of 0 means unlimited.
     */
    public function canAddProduct(Admin $user): bool
    {
        $owner = $this->resolveOwner($user);

        if ($owner->getAccountType() === 'super_admin') {
            return true;
        }

        $limits = $this->getLimits($owner);
        if ($limits === null) {
            return true;
        }

        $limit = (int) ($limits['products'] ?? 0);
        if ($limit === 0) {
            return true; // 0 = unlimited
        }

        $currentProductCount = $this->em->getRepository(\App\Entity\EcomProduct::class)->count([
            'owner' => $owner,
        ]);

        return $currentProductCount < $limit;
    }

    /**
     * Returns the current eCommerce product usage info.
     * Returns ['current' => int, 'limit' => int] where limit 0 = unlimited.
     */
    public function getProductUsage(Admin $user): array
    {
        $owner = $this->resolveOwner($user);
        $limits = $this->getLimits($owner);
        $limit = (int) ($limits['products'] ?? 0);

        $current = $this->em->getRepository(\App\Entity\EcomProduct::class)->count([
            'owner' => $owner,
        ]);

        return [
            'current' => $current,
            'limit'   => $limit,
        ];
    }
}
