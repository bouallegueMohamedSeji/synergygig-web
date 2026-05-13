<?php

namespace App\EventListener;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Event\RequestEvent;

class LastSeenListener
{
    public function __construct(
        private Security $security,
        private EntityManagerInterface $em
    ) {}

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }

        $now = new \DateTime();

        // Update at most once per minute to avoid flush on every sub-request
        $lastSeen = $user->getLastSeenAt();
        if ($lastSeen && ($now->getTimestamp() - $lastSeen->getTimestamp()) < 60) {
            return;
        }

        $user->initLastSeenAt($now);
        $user->setOnlineStatus('online');
        $this->em->flush();
    }
}
