<?php

namespace App\Service;

use App\Entity\Admin;
use App\Entity\R2Settings;
use Doctrine\ORM\EntityManagerInterface;

class R2SettingsService
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {
    }

    /**
     * Resolve the active R2Settings for a given Admin (user).
     * Follows the package feature mode rules and hierarchical fallbacks.
     */
    public function getActiveSettings(Admin $user): ?R2Settings
    {
        // 1. If user is super_admin, they always use their own settings.
        if ($user->getAccountType() === 'super_admin') {
            return $user->getR2Settings();
        }

        // 2. Check the user's subscription package to determine storage mode
        $package = $user->getSubscriptionPackage();
        $mode = 'parent'; // default fallback

        if ($package) {
            $features = $package->getFeatures();
            $mode = $features['media_storage_mode'] ?? 'parent';
        }

        if ($mode === 'user') {
            // Enforced to use their own settings
            return $user->getR2Settings();
        }

        if ($mode === 'choice') {
            // User can choose to use custom settings if they have configured and enabled them
            $userSettings = $user->getR2Settings();
            if ($userSettings && $userSettings->isUseCustom() && $this->isComplete($userSettings)) {
                return $userSettings;
            }
            // otherwise fallback to parent
        }

        // 3. Fallback to Parent/Reseller
        $parent = $user->getParent() ?? $user->getCreatedBy();
        if ($parent) {
            $parentSettings = $this->getActiveSettings($parent);
            if ($this->isComplete($parentSettings)) {
                return $parentSettings;
            }
        }

        // 4. Fallback to Super Admin settings globally if reseller/parent hasn't configured it
        $superAdmin = $this->entityManager->getRepository(Admin::class)->findOneBy(['accountType' => 'super_admin']);
        if ($superAdmin && $superAdmin->getId() !== $user->getId()) {
            $globalSettings = $superAdmin->getR2Settings();
            if ($this->isComplete($globalSettings)) {
                return $globalSettings;
            }
        }

        // Fallback to whatever settings the user has (could be incomplete or null)
        return $user->getR2Settings();
    }

    /**
     * Checks if R2 settings are fully configured.
     */
    public function isComplete(?R2Settings $settings): bool
    {
        if (!$settings) {
            return false;
        }

        return !empty($settings->getAccountId())
            && !empty($settings->getAccessKeyId())
            && !empty($settings->getSecretAccessKey())
            && !empty($settings->getBucketName());
    }
}
