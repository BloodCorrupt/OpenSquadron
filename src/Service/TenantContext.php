<?php

namespace App\Service;

use App\Entity\Admin;
use Doctrine\ORM\EntityManagerInterface;

class TenantContext
{
    private ?Admin $currentOwner = null;

    public function __construct(
        private EntityManagerInterface $entityManager
    ) {
    }

    public function getCurrentOwner(): ?Admin
    {
        return $this->currentOwner;
    }

    public function setCurrentOwner(?Admin $owner): void
    {
        $this->currentOwner = $owner;

        if ($owner !== null) {
            $this->enableTenantFilter($owner->getId());
        } else {
            $this->disableTenantFilter();
        }
    }

    /**
     * Enable Doctrine row-level query filtering.
     */
    public function enableTenantFilter(int $ownerId): void
    {
        $filters = $this->entityManager->getFilters();
        if ($filters->has('tenant_filter')) {
            $filter = $filters->enable('tenant_filter');
            $filter->setParameter('owner_id', $ownerId);
        }
    }

    /**
     * Disable Doctrine row-level query filtering (e.g. for webhooks lookup or super admin list queries).
     */
    public function disableTenantFilter(): void
    {
        $filters = $this->entityManager->getFilters();
        if ($filters->has('tenant_filter') && $filters->isEnabled('tenant_filter')) {
            $filters->disable('tenant_filter');
        }
    }
}
