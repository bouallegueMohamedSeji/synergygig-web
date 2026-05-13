<?php

namespace App\EventListener;

use App\Entity\Attendance;
use App\Repository\AttendanceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

#[AsEventListener(event: LoginSuccessEvent::class, priority: -10)]
class LoginListener
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AttendanceRepository $repo,
    ) {}

    public function __invoke(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if (!$user) {
            return;
        }

        $today = new \DateTime('today');
        $existing = $this->repo->findOneBy(['user' => $user, 'date' => $today]);

        if ($existing) {
            // One record per day — never overwrite check_in
            return;
        }

        $now = new \DateTime();
        $attendance = new Attendance();
        $attendance->setUser($user);
        $attendance->setDate($today);
        $attendance->setCheckIn($now);
        $attendance->initCreatedAt();
        $attendance->setApprovalStatus('PENDING');

        // Detect late (after 09:15)
        $lateThreshold = (new \DateTime('today'))->setTime(9, 15, 0);
        $attendance->setStatus($now > $lateThreshold ? 'LATE' : 'PRESENT');

        $this->em->persist($attendance);
        $this->em->flush();
    }
}
