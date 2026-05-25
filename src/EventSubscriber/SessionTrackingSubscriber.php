<?php

namespace App\EventSubscriber;

use App\Entity\Admin;
use App\Entity\UserSession;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;

class SessionTrackingSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private RequestStack $requestStack
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => 'onLoginSuccess',
            LogoutEvent::class => 'onLogout',
            KernelEvents::REQUEST => 'onKernelRequest',
        ];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof Admin) {
            return;
        }

        $request = $this->requestStack->getCurrentRequest();
        if (!$request) {
            return;
        }

        $session = $request->getSession();
        $session->start(); // ensure session is started
        $sessionId = $session->getId();

        $userSession = $this->em->getRepository(UserSession::class)->findOneBy(['sessionId' => $sessionId]);
        if (!$userSession) {
            $userSession = new UserSession();
            $userSession->setSessionId($sessionId);
            $userSession->setAdmin($user);
            $userSession->setIpAddress($request->getClientIp());
            $userSession->setUserAgent($request->headers->get('User-Agent'));
            $this->em->persist($userSession);
            $this->em->flush();
        }
    }

    public function onLogout(LogoutEvent $event): void
    {
        $request = $event->getRequest();
        $session = $request->getSession();
        if ($session->isStarted()) {
            $sessionId = $session->getId();
            $userSession = $this->em->getRepository(UserSession::class)->findOneBy(['sessionId' => $sessionId]);
            if ($userSession) {
                $this->em->remove($userSession);
                $this->em->flush();
            }
        }
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!$request->hasPreviousSession()) {
            return;
        }

        $session = $request->getSession();
        if (!$session->isStarted()) {
            // Do not start session on every request if it wasn't already started
            return;
        }
        
        $sessionId = $session->getId();
        if (!$sessionId) {
            return;
        }

        // To avoid writing to DB on every single request, we could add a probability or a time diff check,
        // but for simplicity and real-time accuracy, we'll just execute a quick DQL update.
        // Doing raw update to avoid pulling entity into memory every request.
        
        $this->em->createQuery('UPDATE App\Entity\UserSession s SET s.lastActivityAt = :now WHERE s.sessionId = :id')
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('id', $sessionId)
            ->execute();
    }
}
