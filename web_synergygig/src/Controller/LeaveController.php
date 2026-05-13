<?php

namespace App\Controller;

use App\Entity\Leave;
use App\Entity\User;
use App\Form\LeaveType;
use App\Repository\LeaveRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Knp\Component\Pager\PaginatorInterface;
use App\Service\N8nWebhookService;
use App\Service\NotificationService;

#[Route('/leaves')]
#[IsGranted('ROLE_EMPLOYEE')]
class LeaveController extends AbstractController
{
    // Balance limits per year (matching Java desktop)
    private const MAX_VACATION_DAYS = 30;
    private const MAX_SICK_DAYS = 15;
    private const MAX_UNPAID_DAYS = 60;

    #[Route('/', name: 'app_leave_index')]
    public function index(Request $request, LeaveRepository $repo, PaginatorInterface $paginator): Response
    {
        $qb = $repo->createQueryBuilder('l')->orderBy('l.id', 'DESC');

        // Non-HR users see only their own leaves
        if (!$this->isGranted('ROLE_HR')) {
            $qb->andWhere('l.user = :currentUser')->setParameter('currentUser', $this->getUser());
        }

        $status = $request->query->get('status');
        if ($status) {
            $qb->andWhere('l.status = :status')->setParameter('status', $status);
        }

        $type = $request->query->get('type');
        if ($type) {
            $qb->andWhere('l.type = :type')->setParameter('type', $type);
        }

        $pagination = $paginator->paginate($qb, $request->query->getInt('page', 1), 15);

        return $this->render('leave/index.html.twig', [
            'leaves' => $pagination,
            'pagination' => $pagination,
        ]);
    }

    #[Route('/new', name: 'app_leave_new')]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $leave = new Leave();
        $isHr = $this->isGranted('ROLE_HR');

        // Non-HR users can only create leaves for themselves
        $currentUser = $this->getUser();
        if (!$isHr && $currentUser instanceof User) {
            $leave->setUser($currentUser);
            $leave->setStatus('PENDING');
        }
        $form = $this->createForm(LeaveType::class, $leave, ['is_hr' => $isHr]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Enforce: non-HR users can only create for themselves with PENDING
            if (!$isHr) {
                if (!$currentUser instanceof User) {
                    throw $this->createAccessDeniedException('Invalid authenticated user.');
                }
                $leave->setUser($currentUser);
                $leave->setStatus('PENDING');
            }

            // Server-side validation
            $errors = $this->validateLeave($leave);
            if (!empty($errors)) {
                foreach ($errors as $err) {
                    $this->addFlash('error', $err);
                }
                return $this->render('leave/form.html.twig', [
                    'form' => $form->createView(),
                    'is_edit' => false,
                ]);
            }

            if (!$leave->getStatus()) {
                $leave->setStatus('PENDING');
            }

            // Handle optional file attachment
            $file = $form->get('attachmentFile')->getData();
            if ($file) {
                $projectDir = $this->getParameter('kernel.project_dir');
                if (!is_string($projectDir)) {
                    throw new \RuntimeException('Invalid project directory configuration.');
                }
                $uploadDir = $projectDir . '/public/uploads/leaves';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0775, true);
                }
                $filename = uniqid() . '.' . $file->guessExtension();
                $file->move($uploadDir, $filename);
                $leave->setAttachment($filename);
            }

            $em->persist($leave);
            $em->flush();
            $this->addFlash('success', 'Leave request submitted.');
            return $this->redirectToRoute('app_leave_index');
        }

        return $this->render('leave/form.html.twig', [
            'form' => $form->createView(),
            'is_edit' => false,
        ]);
    }

    #[Route('/{id}', name: 'app_leave_show', requirements: ['id' => '\d+'])]
    public function show(Leave $leave, LeaveRepository $repo): Response
    {
        $days = $this->calculateDays($leave);
        $balance = null;
        if ($leave->getUser() && $leave->getType()) {
            $year = $leave->getStartDate() ? (int) $leave->getStartDate()->format('Y') : (int) date('Y');
            $used = $this->getUsedLeaveDays($repo, (int) $leave->getUser()->getId(), $leave->getType(), $year);
            $max = $this->getMaxDays($leave->getType());
            $balance = [
                'used' => $used,
                'max' => $max,
                'remaining' => max(0, $max - $used),
            ];
        }

        return $this->render('leave/show.html.twig', [
            'leave' => $leave,
            'days' => $days,
            'balance' => $balance,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_leave_edit', requirements: ['id' => '\d+'])]
    public function edit(Request $request, Leave $leave, EntityManagerInterface $em): Response
    {
        $isHr = $this->isGranted('ROLE_HR');

        // Only HR or the leave owner can edit; only PENDING leaves can be edited by non-HR
        if (!$isHr) {
            if ($leave->getUser()?->getId() !== $this->getUser()?->getId()) {
                throw $this->createAccessDeniedException('You can only edit your own leave requests.');
            }
            if ($leave->getStatus() !== 'PENDING') {
                $this->addFlash('error', 'Only pending leave requests can be edited.');
                return $this->redirectToRoute('app_leave_show', ['id' => $leave->getId()]);
            }
        }
        $form = $this->createForm(LeaveType::class, $leave, ['is_hr' => $isHr]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Enforce: non-HR cannot change user or status
            $currentUser = $this->getUser();
            if (!$isHr && $currentUser instanceof User) {
                $leave->setUser($currentUser);
                $leave->setStatus('PENDING');
            }

            $errors = $this->validateLeave($leave);
            if (!empty($errors)) {
                foreach ($errors as $err) {
                    $this->addFlash('error', $err);
                }
                return $this->render('leave/form.html.twig', [
                    'form' => $form->createView(),
                    'is_edit' => true,
                    'leave' => $leave,
                ]);
            }

            // Handle optional file attachment
            $file = $form->get('attachmentFile')->getData();
            if ($file) {
                $projectDir = $this->getParameter('kernel.project_dir');
                if (!is_string($projectDir)) {
                    throw new \RuntimeException('Invalid project directory configuration.');
                }
                $uploadDir = $projectDir . '/public/uploads/leaves';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0775, true);
                }
                $filename = uniqid() . '.' . $file->guessExtension();
                $file->move($uploadDir, $filename);
                $leave->setAttachment($filename);
            }

            $em->flush();
            $this->addFlash('success', 'Leave request updated.');
            return $this->redirectToRoute('app_leave_index');
        }

        return $this->render('leave/form.html.twig', [
            'form' => $form->createView(),
            'is_edit' => true,
            'leave' => $leave,
        ]);
    }

    #[Route('/{id}/approve', name: 'app_leave_approve', methods: ['POST'])]
    #[IsGranted('ROLE_HR')]
    public function approve(Request $request, Leave $leave, EntityManagerInterface $em, LeaveRepository $repo, N8nWebhookService $n8n, NotificationService $notifier): Response
    {
        if (!$this->isCsrfTokenValid('approve' . $leave->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_leave_show', ['id' => $leave->getId()]);
        }

        if ($leave->getStatus() !== 'PENDING') {
            $this->addFlash('error', 'Only pending requests can be approved.');
            return $this->redirectToRoute('app_leave_show', ['id' => $leave->getId()]);
        }

        // Check balance
        $requestedDays = $this->calculateDays($leave);
        if ($leave->getUser() && $leave->getType() && $leave->getStartDate()) {
            $year = (int) $leave->getStartDate()->format('Y');
            $used = $this->getUsedLeaveDays($repo, (int) $leave->getUser()->getId(), $leave->getType(), $year);
            $max = $this->getMaxDays($leave->getType());

            if (($used + $requestedDays) > $max) {
                $remaining = max(0, $max - $used);
                $this->addFlash('error', sprintf(
                    'Cannot approve: requesting %d days but only %d %s days remaining this year (used %d/%d).',
                    $requestedDays, $remaining, $leave->getType(), $used, $max
                ));
                return $this->redirectToRoute('app_leave_show', ['id' => $leave->getId()]);
            }
        }

        $leave->setStatus('APPROVED');
        $em->flush();

        $n8n->leaveStatusChanged(
            (int) $leave->getId(),
            $leave->getUser() ? $leave->getUser()->getFirstName() . ' ' . $leave->getUser()->getLastName() : 'N/A',
            $leave->getType() ?? 'Leave',
            'APPROVED',
            'HR'
        );

        if ($leave->getUser()) {
            $notifier->leaveApproved($leave->getUser(), (int) $leave->getId(), $leave->getType() ?? 'Leave');
        }

        $this->addFlash('success', 'Leave request approved.');
        return $this->redirectToRoute('app_leave_show', ['id' => $leave->getId()]);
    }

    #[Route('/{id}/reject', name: 'app_leave_reject', methods: ['POST'])]
    #[IsGranted('ROLE_HR')]
    public function reject(Request $request, Leave $leave, EntityManagerInterface $em, N8nWebhookService $n8n, NotificationService $notifier): Response
    {
        if (!$this->isCsrfTokenValid('reject' . $leave->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_leave_show', ['id' => $leave->getId()]);
        }

        if ($leave->getStatus() !== 'PENDING') {
            $this->addFlash('error', 'Only pending requests can be rejected.');
            return $this->redirectToRoute('app_leave_show', ['id' => $leave->getId()]);
        }

        $rejectionReason = trim((string) $request->request->get('rejection_reason', ''));
        if (strlen($rejectionReason) < 5) {
            $this->addFlash('error', 'Rejection reason must be at least 5 characters.');
            return $this->redirectToRoute('app_leave_show', ['id' => $leave->getId()]);
        }

        $leave->setStatus('REJECTED');
        $leave->setRejectionReason($rejectionReason);
        $em->flush();

        $n8n->leaveStatusChanged(
            (int) $leave->getId(),
            $leave->getUser() ? $leave->getUser()->getFirstName() . ' ' . $leave->getUser()->getLastName() : 'N/A',
            $leave->getType() ?? 'Leave',
            'REJECTED',
            'HR'
        );

        if ($leave->getUser()) {
            $notifier->leaveRejected($leave->getUser(), (int) $leave->getId(), $leave->getType() ?? 'Leave', $rejectionReason);
        }

        $this->addFlash('success', 'Leave request rejected.');
        return $this->redirectToRoute('app_leave_show', ['id' => $leave->getId()]);
    }

    #[Route('/{id}/delete', name: 'app_leave_delete', methods: ['POST'])]
    #[IsGranted('ROLE_HR')]
    public function delete(Request $request, Leave $leave, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete' . $leave->getId(), (string) $request->request->get('_token'))) {
            $em->remove($leave);
            $em->flush();
            $this->addFlash('success', 'Leave request deleted.');
        }

        return $this->redirectToRoute('app_leave_index');
    }

    /** @return string[] */
    private function validateLeave(Leave $leave): array
    {
        $errors = [];

        if (!$leave->getUser()) {
            $errors[] = 'An employee must be selected.';
        }

        if (!$leave->getType()) {
            $errors[] = 'Leave type is required.';
        }

        $start = $leave->getStartDate();
        $end = $leave->getEndDate();

        if (!$start) {
            $errors[] = 'Start date is required.';
        }
        if (!$end) {
            $errors[] = 'End date is required.';
        }

        if ($start && $end) {
            if ($end < $start) {
                $errors[] = 'End date must be after or equal to start date.';
            }

            $now = new \DateTime();
            $minDate = (clone $now)->modify('-1 year');
            $maxDate = (clone $now)->modify('+1 year');

            if ($start < $minDate || $start > $maxDate) {
                $errors[] = 'Start date must be within a reasonable range (1 year past/future).';
            }
            if ($end < $minDate || $end > $maxDate) {
                $errors[] = 'End date must be within a reasonable range (1 year past/future).';
            }
        }

        $reason = $leave->getReason();
        if (!$reason || strlen(trim($reason)) < 5) {
            $errors[] = 'Reason must be at least 5 characters.';
        }

        return $errors;
    }

    /**
     * Calculate the number of days for a leave request (inclusive).
     */
    private function calculateDays(Leave $leave): int
    {
        if (!$leave->getStartDate() || !$leave->getEndDate()) {
            return 0;
        }
        $start = new \DateTime($leave->getStartDate()->format('Y-m-d'));
        $end = new \DateTime($leave->getEndDate()->format('Y-m-d'));
        $diff = $start->diff($end);
        return $diff->days + 1; // inclusive
    }

    /**
     * Get total approved leave days for a user/type/year.
     */
    private function getUsedLeaveDays(LeaveRepository $repo, int $userId, string $type, int $year): int
    {
        $yearStart = new \DateTime("$year-01-01");
        $yearEnd   = new \DateTime(($year + 1) . "-01-01");

        $leaves = $repo->createQueryBuilder('l')
            ->where('l.user = :userId')
            ->andWhere('l.type = :type')
            ->andWhere('l.status = :status')
            ->andWhere('l.start_date >= :yearStart')
            ->andWhere('l.start_date < :yearEnd')
            ->setParameter('userId', $userId)
            ->setParameter('type', $type)
            ->setParameter('status', 'APPROVED')
            ->setParameter('yearStart', $yearStart)
            ->setParameter('yearEnd', $yearEnd)
            ->getQuery()
            ->getResult();

        $total = 0;
        foreach ($leaves as $l) {
            $total += $this->calculateDays($l);
        }
        return $total;
    }

    /**
     * Get the maximum allowed days per year for a leave type.
     */
    private function getMaxDays(string $type): int
    {
        return match (strtoupper($type)) {
            'VACATION' => self::MAX_VACATION_DAYS,
            'SICK' => self::MAX_SICK_DAYS,
            'UNPAID' => self::MAX_UNPAID_DAYS,
            default => 30,
        };
    }
}
