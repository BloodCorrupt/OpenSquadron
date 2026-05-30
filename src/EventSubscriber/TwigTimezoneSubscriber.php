<?php

namespace App\EventSubscriber;

use App\Entity\Admin;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Environment;
use Twig\Extension\CoreExtension;

class TwigTimezoneSubscriber implements EventSubscriberInterface
{
    private Environment $twig;
    private Security $security;

    public function __construct(Environment $twig, Security $security)
    {
        $this->twig = $twig;
        $this->security = $security;
    }

    public static function getSubscribedEvents(): array
    {
        // Run after the firewall (priority 8) so the user is authenticated
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 0],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->security->getUser();
        if ($user instanceof Admin && $user->getTimezone()) {
            try {
                $this->twig->getExtension(CoreExtension::class)->setTimezone($user->getTimezone());
            } catch (\Exception $e) {
                // Fallback to UTC if timezone string is invalid
                $this->twig->getExtension(CoreExtension::class)->setTimezone('UTC');
            }
        } else {
            $this->twig->getExtension(CoreExtension::class)->setTimezone('UTC');
        }
    }
}
