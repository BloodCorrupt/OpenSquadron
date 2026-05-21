<?php

namespace App\EventListener;

use App\Entity\Admin;
use App\Service\TenantContext;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class TenantDatabaseSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private TenantContext $tenantContext
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', -10],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $token = $this->tokenStorage->getToken();
        $user = $token?->getUser();

        if ($user instanceof Admin) {
            $owner = $user;
            if ($user->getAccountType() === 'team' && $user->getParent() !== null) {
                $owner = $user->getParent();
            }
            
            $this->tenantContext->setCurrentOwner($owner);
        }
    }
}
