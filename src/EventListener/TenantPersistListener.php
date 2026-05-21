<?php

namespace App\EventListener;

use App\Entity\TenantAwareInterface;
use App\Service\TenantContext;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;

#[AsDoctrineListener(event: Events::prePersist, priority: 500)]
class TenantPersistListener
{
    public function __construct(
        private TenantContext $tenantContext
    ) {
    }

    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $args->getObject();

        // Check if the entity is tenant aware
        if ($entity instanceof TenantAwareInterface) {
            // Automatically set current workspace owner if not explicitly set
            if ($entity->getOwner() === null) {
                $owner = $this->tenantContext->getCurrentOwner();
                if ($owner !== null) {
                    $entity->setOwner($owner);
                }
            }
        }
    }
}
