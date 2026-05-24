<?php

namespace App\EventSubscriber;

use App\Entity\Admin;
use App\Entity\ResellerBranding;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class DomainContextSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        // Only run on main request
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $host = $request->getHost();

        // Optional: Exclude local/base domains if needed, or just let DB do the lookup
        // Check if there is a ResellerBranding for this custom domain
        $branding = $this->entityManager->getRepository(ResellerBranding::class)->findOneBy(['customDomain' => $host]);

        if ($branding) {
            $owner = $branding->getOwner();
            if ($owner) {
                // Inject the owner and branding into request attributes
                $request->attributes->set('_reseller_owner', $owner);
                $request->attributes->set('_reseller_branding', $branding);
            }
        } else {
            // Check if there is a base platform branding for super admin?
            // For now, if no custom domain matches, we assume it's the base platform.
            $superAdmin = $this->entityManager->getRepository(Admin::class)->findOneBy(['accountType' => 'super_admin']);
            if ($superAdmin) {
                $request->attributes->set('_reseller_owner', $superAdmin);
                if ($superAdmin->getBranding()) {
                    $request->attributes->set('_reseller_branding', $superAdmin->getBranding());
                }
            }
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 30], // Run early
        ];
    }
}
