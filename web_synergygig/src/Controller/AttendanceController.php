<?php

namespace App\Controller;

use App\Entity\Attendance;
use App\Form\AttendanceType;
use App\Repository\AttendanceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Knp\Component\Pager\PaginatorInterface;

#[Route('/attendance')]
#[IsGranted('ROLE_USER')]
class AttendanceController extends AbstractController
{
    #[Route('/', name: 'app_attendance_index')]
    public function index(Request $request, AttendanceRepository $repo, PaginatorInterface $paginator): Response
    {
        $qb = $repo->createQueryBuilder('a')->orderBy('a.id', 'DESC');

        // Non-HR users see only their own attendance
        if (!$this->isGranted('ROLE_HR')) {
            $qb->andWhere('a.user = :currentUser')->setParameter('currentUser', $this->getUser());
        }

        $status = $request->query->get('status');
        if ($status) {
            $qb->andWhere('a.status = :status')->setParameter('status', $status);
        }

        $dateFrom = $request->query->get('date_from');
        $dateTo = $request->query->get('date_to');
        if ($dateFrom) {
            $qb->andWhere('a.date >= :dateFrom')->setParameter('dateFrom', new \DateTime((string) $dateFrom));
        }
        if ($dateTo) {
            $qb->andWhere('a.date <= :dateTo')->setParameter('dateTo', new \DateTime((string) $dateTo));
        }

        $pagination = $paginator->paginate($qb, $request->query->getInt('page', 1), 20);

        return $this->render('attendance/index.html.twig', [
            'records' => $pagination,
            'pagination' => $pagination,
        ]);
    }

    #[Route('/new', name: 'app_attendance_new')]
    #[IsGranted('ROLE_HR')]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $attendance = new Attendance();
        $attendance->setDate(new \DateTime());
        $form = $this->createForm(AttendanceType::class, $attendance);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $errors = $this->validateAttendance($attendance);
            if (!empty($errors)) {
                foreach ($errors as $err) {
                    $this->addFlash('error', $err);
                }
                return $this->render('attendance/form.html.twig', [
                    'form' => $form->createView(),
                    'is_edit' => false,
                ]);
            }
            $this->detectLateStatus($attendance);
            $em->persist($attendance);
            $em->flush();
            $this->addFlash('success', 'Attendance record created.');
            return $this->redirectToRoute('app_attendance_index');
        }

        return $this->render('attendance/form.html.twig', [
            'form' => $form->createView(),
            'is_edit' => false,
        ]);
    }

    #[Route('/{id}', name: 'app_attendance_show', requirements: ['id' => '\d+'])]
    public function show(Attendance $attendance): Response
    {
        return $this->render('attendance/show.html.twig', [
            'record' => $attendance,
            'hoursWorked' => $this->calculateHours($attendance),
            'overtime' => $this->calculateOvertime($attendance),
        ]);
    }

    #[Route('/{id}/edit', name: 'app_attendance_edit', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_HR')]
    public function edit(Request $request, Attendance $attendance, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(AttendanceType::class, $attendance);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $errors = $this->validateAttendance($attendance);
            if (!empty($errors)) {
                foreach ($errors as $err) {
                    $this->addFlash('error', $err);
                }
                return $this->render('attendance/form.html.twig', [
                    'form' => $form->createView(),
                    'is_edit' => true,
                    'record' => $attendance,
                ]);
            }
            $this->detectLateStatus($attendance);
            $em->flush();
            $this->addFlash('success', 'Attendance record updated.');
            return $this->redirectToRoute('app_attendance_index');
        }

        return $this->render('attendance/form.html.twig', [
            'form' => $form->createView(),
            'is_edit' => true,
            'record' => $attendance,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_attendance_delete', methods: ['POST'])]
    #[IsGranted('ROLE_HR')]
    public function delete(Request $request, Attendance $attendance, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete' . $attendance->getId(), (string) $request->request->get('_token'))) {
            $em->remove($attendance);
            $em->flush();
            $this->addFlash('success', 'Attendance record deleted.');
        }
        return $this->redirectToRoute('app_attendance_index');
    }

    // ── HR Approve ──
    #[Route('/{id}/approve', name: 'app_attendance_approve', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_HR')]
    public function approve(Request $request, Attendance $attendance, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('approve' . $attendance->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_attendance_index');
        }
        $attendance->setApprovalStatus('APPROVED');
        $attendance->setRejectionReason(null);
        $em->flush();
        $this->addFlash('success', 'Attendance approved for ' . $attendance->getUser()?->getFirstName() . ' ' . $attendance->getUser()?->getLastName() . '.');
        return $this->redirectToRoute('app_attendance_index');
    }

    // ── HR Reject ──
    #[Route('/{id}/reject', name: 'app_attendance_reject', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_HR')]
    public function reject(Request $request, Attendance $attendance, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('reject' . $attendance->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_attendance_index');
        }
        $reason = trim((string) $request->request->get('rejection_reason', ''));
        $attendance->setApprovalStatus('REJECTED');
        $attendance->setRejectionReason($reason ?: null);
        $em->flush();
        $this->addFlash('warning', 'Attendance record rejected.');
        return $this->redirectToRoute('app_attendance_index');
    }

    // ── Heartbeat (updates check_out = now, called every 3 min by JS) ──
    #[Route('/heartbeat', name: 'app_attendance_heartbeat', methods: ['POST'])]
    public function heartbeat(EntityManagerInterface $em, AttendanceRepository $repo): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) { return $this->json(['status' => 'no-user'], 401); }
        $record = $repo->findOneBy(['user' => $user, 'date' => new \DateTime('today')]);
        if ($record && $record->getCheckIn()) {
            $record->setCheckOut(new \DateTime());
            $em->flush();
        }
        return $this->json(['status' => 'ok']);
    }

    // ── Auto checkout (sendBeacon on page unload) ──
    #[Route('/auto-checkout', name: 'app_attendance_auto_checkout', methods: ['POST'])]
    public function autoCheckout(EntityManagerInterface $em, AttendanceRepository $repo): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) { return $this->json(['status' => 'no-user'], 401); }
        $record = $repo->findOneBy(['user' => $user, 'date' => new \DateTime('today')]);
        if ($record && $record->getCheckIn()) {
            $record->setCheckOut(new \DateTime());
            $em->flush();
        }
        return $this->json(['status' => 'ok']);
    }

    // ── Check-in ──
    #[Route('/checkin', name: 'app_attendance_checkin', methods: ['POST'])]
    public function checkin(Request $request, EntityManagerInterface $em, AttendanceRepository $repo): Response
    {
        if (!$this->isCsrfTokenValid('attendance_checkin', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_attendance_index');
        }

        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            $this->addFlash('danger', 'You must be logged in.');
            return $this->redirectToRoute('app_login');
        }

        // Check if already checked in today
        $today = new \DateTime('today');
        $existing = $repo->findOneBy(['user' => $user, 'date' => $today]);

        if ($existing && $existing->getCheckIn()) {
            $this->addFlash('warning', 'You have already checked in today.');
            return $this->redirectToRoute('app_attendance_index');
        }

        $now = new \DateTime();
        if (!$existing) {
            $existing = new Attendance();
            $existing->setUser($user);
            $existing->setDate($today);
            $existing->initCreatedAt();
            $em->persist($existing);
        }

        $existing->setCheckIn($now);
        $existing->setStatus('PRESENT');
        $existing->setApprovalStatus('PENDING');
        $this->detectLateStatus($existing);
        $em->flush();
        $this->addFlash('success', 'Checked in at ' . $now->format('H:i') . ($existing->getStatus() === 'LATE' ? ' (Late)' : ''));
        return $this->redirectToRoute('app_attendance_index');
    }

    // ── Check-out ──
    #[Route('/checkout', name: 'app_attendance_checkout', methods: ['POST'])]
    public function checkout(Request $request, EntityManagerInterface $em, AttendanceRepository $repo): Response
    {
        if (!$this->isCsrfTokenValid('attendance_checkout', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_attendance_index');
        }

        $user = $this->getUser();
        if (!$user) {
            $this->addFlash('danger', 'You must be logged in.');
            return $this->redirectToRoute('app_login');
        }

        $today = new \DateTime('today');
        $existing = $repo->findOneBy(['user' => $user, 'date' => $today]);

        if (!$existing || !$existing->getCheckIn()) {
            $this->addFlash('warning', 'You haven\'t checked in today.');
            return $this->redirectToRoute('app_attendance_index');
        }

        // Always update check_out to latest time (one-record-per-day rule)
        $now = new \DateTime();
        $existing->setCheckOut($now);
        $em->flush();

        $hours = $this->calculateHours($existing);
        $this->addFlash('success', sprintf('Checked out at %s — worked %.1f hours', $now->format('H:i'), $hours));
        return $this->redirectToRoute('app_attendance_index');
    }

    // ── Business logic ──

    /** @return string[] */
    private function validateAttendance(Attendance $attendance): array
    {
        $errors = [];
        if (!$attendance->getUser()) {
            $errors[] = 'Please select an employee.';
        }
        if (!$attendance->getDate()) {
            $errors[] = 'Date is required.';
        } else {
            $now = new \DateTime();
            $minDate = new \DateTime('-1 year');
            $maxDate = new \DateTime('+7 days');
            if ($attendance->getDate() < $minDate) {
                $errors[] = 'Date cannot be more than 1 year in the past.';
            }
            if ($attendance->getDate() > $maxDate) {
                $errors[] = 'Date cannot be more than 7 days in the future.';
            }
        }
        if (!$attendance->getStatus()) {
            $errors[] = 'Please select a status.';
        }
        $checkIn = $attendance->getCheckIn();
        $checkOut = $attendance->getCheckOut();
        if ($checkIn && $checkOut && $checkOut <= $checkIn) {
            $errors[] = 'Check-out time must be after check-in time.';
        }
        return $errors;
    }

    private function detectLateStatus(Attendance $attendance): void
    {
        $checkIn = $attendance->getCheckIn();
        if (!$checkIn) {
            return;
        }

        // Late if check-in after 09:15
        // Cast to DateTime so setTime() is available (DateTimeInterface has no setTime)
        $lateThreshold = \DateTime::createFromInterface($checkIn)->setTime(9, 15, 0);
        if ($checkIn > $lateThreshold && $attendance->getStatus() !== 'ABSENT') {
            $attendance->setStatus('LATE');
        }
    }

    private function calculateHours(Attendance $attendance): float
    {
        $checkIn = $attendance->getCheckIn();
        $checkOut = $attendance->getCheckOut();
        if (!$checkIn || !$checkOut) {
            return 0.0;
        }

        $diff = $checkOut->getTimestamp() - $checkIn->getTimestamp();
        return round($diff / 3600, 2);
    }

    private function calculateOvertime(Attendance $attendance): float
    {
        $hours = $this->calculateHours($attendance);
        return max(0, $hours - 8.0);
    }
}
