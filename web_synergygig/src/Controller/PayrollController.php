<?php

namespace App\Controller;

use App\Entity\Payroll;
use App\Entity\Department;
use App\Entity\User;
use App\Form\PayrollType;
use App\Repository\AttendanceRepository;
use App\Repository\PayrollRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Knp\Component\Pager\PaginatorInterface;
use App\Service\N8nWebhookService;
use App\Service\NotificationService;

#[Route('/payroll')]
class PayrollController extends AbstractController
{
    #[Route('/', name: 'app_payroll_index')]
    #[IsGranted('ROLE_HR')]
    public function index(Request $request, PayrollRepository $repo, UserRepository $userRepo, PaginatorInterface $paginator): Response
    {
        $qb = $repo->createQueryBuilder('p')->orderBy('p.id', 'DESC');

        $status = $request->query->get('status');
        if ($status) {
            $qb->andWhere('p.status = :status')->setParameter('status', $status);
        }

        $pagination = $paginator->paginate($qb, $request->query->getInt('page', 1), 15);

        // All non-GIG employees for the "full employees" view
        $currentMonth = (int) date('m');
        $currentYear  = (int) date('Y');
        $allEmployees = $userRepo->createQueryBuilder('u')
            ->where("u.role NOT IN (:excluded)")
            ->setParameter('excluded', ['GIG_WORKER'])
            ->orderBy('u.last_name', 'ASC')
            ->getQuery()->getResult();

        // Map userId => current-month payroll (null if none)
        $payrollMap = [];
        foreach ($allEmployees as $emp) {
            $payrollMap[$emp->getId()] = $repo->findOneBy(['user' => $emp, 'month' => $currentMonth, 'year' => $currentYear]);
        }

        return $this->render('payroll/index.html.twig', [
            'payrolls'     => $pagination,
            'pagination'   => $pagination,
            'allEmployees' => $allEmployees,
            'payrollMap'   => $payrollMap,
            'currentMonth' => $currentMonth,
            'currentYear'  => $currentYear,
        ]);
    }

    #[Route('/mine', name: 'app_payroll_mine')]
    #[IsGranted('ROLE_EMPLOYEE')]
    public function myPayrolls(PayrollRepository $repo): Response
    {
        $payrolls = $repo->findBy(['user' => $this->getUser()], ['year' => 'DESC', 'month' => 'DESC']);
        return $this->render('payroll/mine.html.twig', [
            'payrolls' => $payrolls,
        ]);
    }

    #[Route('/new', name: 'app_payroll_new')]
    #[IsGranted('ROLE_HR')]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $payroll = new Payroll();
        $form = $this->createForm(PayrollType::class, $payroll);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $errors = $this->validatePayroll($payroll);
            if (!empty($errors)) {
                foreach ($errors as $err) {
                    $this->addFlash('error', $err);
                }
                return $this->render('payroll/form.html.twig', [
                    'form' => $form->createView(),
                    'is_edit' => false,
                ]);
            }

            $payroll->initGeneratedAt(new \DateTime());
            $em->persist($payroll);
            $em->flush();
            $this->addFlash('success', 'Payroll record created.');
            return $this->redirectToRoute('app_payroll_index');
        }

        return $this->render('payroll/form.html.twig', [
            'form' => $form->createView(),
            'is_edit' => false,
        ]);
    }

    #[Route('/generate', name: 'app_payroll_generate')]
    #[IsGranted('ROLE_HR')]
    public function generate(
        Request $request,
        EntityManagerInterface $em,
        UserRepository $userRepo,
        AttendanceRepository $attendanceRepo,
        PayrollRepository $payrollRepo,
        N8nWebhookService $n8n,
        NotificationService $notifier
    ): Response {
        $users = $userRepo->findBy(['is_active' => true]);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('payroll_generate', (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Invalid CSRF token.');
                return $this->redirectToRoute('app_payroll_generate');
            }

            $userId = (int) $request->request->get('user_id');
            $month = (int) $request->request->get('month');
            $year = (int) $request->request->get('year');
            $bonusInput = (float) $request->request->get('bonus', 0);
            $deductionsInput = (float) $request->request->get('deductions', 0);

            // Validation
            if ($month < 1 || $month > 12) {
                $this->addFlash('error', 'Month must be between 1 and 12.');
                return $this->redirectToRoute('app_payroll_generate');
            }
            if ($year < 2020 || $year > 2099) {
                $this->addFlash('error', 'Year must be between 2020 and 2099.');
                return $this->redirectToRoute('app_payroll_generate');
            }
            if ($bonusInput < 0) {
                $this->addFlash('error', 'Bonus cannot be negative.');
                return $this->redirectToRoute('app_payroll_generate');
            }
            if ($deductionsInput < 0) {
                $this->addFlash('error', 'Deductions cannot be negative.');
                return $this->redirectToRoute('app_payroll_generate');
            }

            $user = $userRepo->find($userId);
            if (!$user) {
                $this->addFlash('error', 'Employee not found.');
                return $this->redirectToRoute('app_payroll_generate');
            }

            // Check duplicate
            $existing = $payrollRepo->findOneBy(['user' => $user, 'month' => $month, 'year' => $year]);
            if ($existing) {
                $this->addFlash('error', sprintf(
                    'Payroll already exists for %s %s (%s/%d). Edit or delete the existing record first.',
                    $user->getFirstName(), $user->getLastName(), $month, $year
                ));
                return $this->redirectToRoute('app_payroll_generate');
            }

            // Calculate hours from attendance
            $totalHours = $this->calculateMonthlyHours($attendanceRepo, $user, $month, $year);
            $hourlyRate = (float) ($user->getHourlyRate() ?? 0);
            $monthlySalary = (float) ($user->getMonthlySalary() ?? 0);

            // Base salary: use monthly salary if set, otherwise hourlyRate * hours
            if ($monthlySalary > 0) {
                $baseSalary = $monthlySalary;
            } else {
                $baseSalary = $hourlyRate * $totalHours;
            }

            $netSalary = $baseSalary + $bonusInput - $deductionsInput;

            $payroll = new Payroll();
            $payroll->setUser($user);
            $payroll->setMonth($month);
            $payroll->setYear($year);
            $payroll->setBaseSalary((string) round($baseSalary, 2));
            $payroll->setBonus((string) round($bonusInput, 2));
            $payroll->setDeductions((string) round($deductionsInput, 2));
            $payroll->setNetSalary((string) round($netSalary, 2));
            $payroll->setAmount((string) round($netSalary, 2));
            $payroll->setTotalHoursWorked(round($totalHours, 2));
            $payroll->setHourlyRate($hourlyRate);
            $payroll->setStatus('PENDING');
            $payroll->initGeneratedAt(new \DateTime());

            $em->persist($payroll);
            $em->flush();

            try {
                $n8n->payrollGenerated(1, $month . '/' . $year, (float) $netSalary);
            } catch (\Throwable $e) {
                // n8n webhook failure should not block payroll generation
            }

            $notifier->payrollGenerated($user, (int) $payroll->getId(), $month . '/' . $year, (float) $netSalary);

            $this->addFlash('success', sprintf(
                'Payroll generated for %s %s — Net: %.2f',
                $user->getFirstName(), $user->getLastName(), $netSalary
            ));
            return $this->redirectToRoute('app_payroll_show', ['id' => $payroll->getId()]);
        }

        return $this->render('payroll/generate.html.twig', [
            'users' => $users,
            'current_month' => (int) date('m'),
            'current_year' => (int) date('Y'),
        ]);
    }

    #[Route('/generate-all', name: 'app_payroll_generate_all', methods: ['POST'])]
    #[IsGranted('ROLE_HR')]
    public function generateAll(
        Request $request,
        EntityManagerInterface $em,
        UserRepository $userRepo,
        AttendanceRepository $attendanceRepo,
        PayrollRepository $payrollRepo,
        N8nWebhookService $n8n,
        NotificationService $notifier
    ): Response {
        if (!$this->isCsrfTokenValid('payroll_generate_all', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_payroll_generate');
        }

        $month = (int) $request->request->get('month', date('m'));
        $year = (int) $request->request->get('year', date('Y'));

        if ($month < 1 || $month > 12 || $year < 2020 || $year > 2099) {
            $this->addFlash('error', 'Invalid month or year.');
            return $this->redirectToRoute('app_payroll_generate');
        }

        $users = $userRepo->findBy(['is_active' => true]);
        $generated = 0;
        $skipped = 0;
        $totalNet = 0.0;

        foreach ($users as $user) {
            // Skip if payroll already exists for this month
            $existing = $payrollRepo->findOneBy(['user' => $user, 'month' => $month, 'year' => $year]);
            if ($existing) {
                $skipped++;
                continue;
            }

            $totalHours = $this->calculateMonthlyHours($attendanceRepo, $user, $month, $year);
            $hourlyRate = (float) ($user->getHourlyRate() ?? 0);
            $monthlySalary = (float) ($user->getMonthlySalary() ?? 0);

            if ($monthlySalary > 0) {
                $baseSalary = $monthlySalary;
            } else {
                $baseSalary = $hourlyRate * $totalHours;
            }

            // Skip users with no salary basis
            if ($baseSalary <= 0) {
                $skipped++;
                continue;
            }

            $netSalary = $baseSalary;

            $payroll = new Payroll();
            $payroll->setUser($user);
            $payroll->setMonth($month);
            $payroll->setYear($year);
            $payroll->setBaseSalary((string) round($baseSalary, 2));
            $payroll->setBonus('0');
            $payroll->setDeductions('0');
            $payroll->setNetSalary((string) round($netSalary, 2));
            $payroll->setAmount((string) round($netSalary, 2));
            $payroll->setTotalHoursWorked(round($totalHours, 2));
            $payroll->setHourlyRate($hourlyRate);
            $payroll->setStatus('PENDING');
            $payroll->initGeneratedAt(new \DateTime());

            $em->persist($payroll);
            $generated++;
            $totalNet += $netSalary;

            $notifier->payrollGenerated($user, 0, $month . '/' . $year, (float) $netSalary);
        }

        $em->flush();

        // Update payroll IDs in notifications (they now have IDs after flush)
        try {
            $n8n->payrollGenerated($generated, $month . '/' . $year, $totalNet);
        } catch (\Throwable $e) {
            // n8n webhook failure should not block
        }

        $this->addFlash('success', sprintf(
            'Payroll generated for %d employees (skipped %d). Total net: %.2f',
            $generated, $skipped, $totalNet
        ));
        return $this->redirectToRoute('app_payroll_index');
    }

    #[Route('/{id}', name: 'app_payroll_show', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_EMPLOYEE')]
    public function show(Payroll $payroll): Response
    {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            throw $this->createAccessDeniedException('You must be logged in.');
        }

        // Employees can only view their own payroll
        if (!$this->isGranted('ROLE_HR') && $payroll->getUser()?->getId() !== $currentUser->getId()) {
            throw $this->createAccessDeniedException('You can only view your own payroll records.');
        }
        return $this->render('payroll/show.html.twig', [
            'payroll' => $payroll,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_payroll_edit', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_HR')]
    public function edit(Request $request, Payroll $payroll, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(PayrollType::class, $payroll);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $errors = $this->validatePayroll($payroll);
            if (!empty($errors)) {
                foreach ($errors as $err) {
                    $this->addFlash('error', $err);
                }
                return $this->render('payroll/form.html.twig', [
                    'form' => $form->createView(),
                    'is_edit' => true,
                    'payroll' => $payroll,
                ]);
            }

            $em->flush();
            $this->addFlash('success', 'Payroll record updated.');
            return $this->redirectToRoute('app_payroll_index');
        }

        return $this->render('payroll/form.html.twig', [
            'form' => $form->createView(),
            'is_edit' => true,
            'payroll' => $payroll,
        ]);
    }

    #[Route('/{id}/mark-paid', name: 'app_payroll_mark_paid', methods: ['POST'])]
    #[IsGranted('ROLE_HR')]
    public function markPaid(Request $request, Payroll $payroll, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('pay' . $payroll->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_payroll_show', ['id' => $payroll->getId()]);
        }

        if ($payroll->getStatus() === 'PAID') {
            $this->addFlash('error', 'This payroll is already marked as paid.');
            return $this->redirectToRoute('app_payroll_show', ['id' => $payroll->getId()]);
        }

        $payroll->setStatus('PAID');
        $em->flush();
        $this->addFlash('success', 'Payroll marked as paid.');
        return $this->redirectToRoute('app_payroll_show', ['id' => $payroll->getId()]);
    }

    #[Route('/{id}/delete', name: 'app_payroll_delete', methods: ['POST'])]
    #[IsGranted('ROLE_HR')]
    public function delete(Request $request, Payroll $payroll, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete' . $payroll->getId(), (string) $request->request->get('_token'))) {
            $em->remove($payroll);
            $em->flush();
            $this->addFlash('success', 'Payroll record deleted.');
        }
        return $this->redirectToRoute('app_payroll_index');
    }

    /**
     * @return list<string>
     */
    private function validatePayroll(Payroll $payroll): array
    {
        $errors = [];

        if (!$payroll->getUser()) {
            $errors[] = 'An employee must be selected.';
        }

        $month = $payroll->getMonth();
        if (!$month || $month < 1 || $month > 12) {
            $errors[] = 'Month must be between 1 and 12.';
        }

        $year = $payroll->getYear();
        if (!$year || $year < 2020 || $year > 2099) {
            $errors[] = 'Year must be between 2020 and 2099.';
        }

        $baseSalary = (float) $payroll->getBaseSalary();
        if ($baseSalary < 0) {
            $errors[] = 'Base salary cannot be negative.';
        }

        $bonus = (float) $payroll->getBonus();
        if ($bonus < 0) {
            $errors[] = 'Bonus cannot be negative.';
        }

        $deductions = (float) $payroll->getDeductions();
        if ($deductions < 0) {
            $errors[] = 'Deductions cannot be negative.';
        }

        if (!$payroll->getStatus()) {
            $errors[] = 'Status is required.';
        }

        return $errors;
    }

    #[Route('/department-overview', name: 'app_payroll_department_overview')]
    #[IsGranted('ROLE_HR')]
    public function departmentOverview(UserRepository $userRepo, EntityManagerInterface $em): Response
    {
        $employees = $userRepo->createQueryBuilder('u')
            ->where("u.role IS NULL OR u.role != :gig")
            ->setParameter('gig', 'GIG_WORKER')
            ->leftJoin('u.department', 'd')
            ->addSelect('d')
            ->orderBy('u.lastName', 'ASC')
            ->getQuery()->getResult();

        $departments = $em->getRepository(Department::class)->findBy([], ['name' => 'ASC'], 200);

        $deptStats = [];
        foreach ($departments as $dept) {
            $deptEmployees = array_values(array_filter($employees, fn($u) => $u->getDepartment() && $u->getDepartment()->getId() === $dept->getId()));
            $allocated     = (float) array_sum(array_map(fn($u) => (float) ($u->getMonthlySalary() ?? 0), $deptEmployees));
            $budget        = (float) ($dept->getAllocatedBudget() ?? 0);
            $remaining     = $budget - $allocated;
            $pct           = $budget > 0 ? round($allocated / $budget * 100, 1) : 0;
            $status        = $budget <= 0 ? 'no-budget' : ($allocated > $budget ? 'over' : ($pct >= 80 ? 'near' : 'under'));
            $deptStats[]   = [
                'dept'      => $dept,
                'budget'    => $budget,
                'allocated' => $allocated,
                'remaining' => $remaining,
                'pct'       => $pct,
                'status'    => $status,
                'employees' => $deptEmployees,
            ];
        }

        $totalBudget      = array_sum(array_column($deptStats, 'budget'));
        $totalAllocated   = array_sum(array_column($deptStats, 'allocated'));
        $noDeptEmployees  = array_values(array_filter($employees, fn($u) => !$u->getDepartment()));

        return $this->render('payroll/overview.html.twig', [
            'deptStats'       => $deptStats,
            'employees'       => $employees,
            'noDeptEmployees' => $noDeptEmployees,
            'totalBudget'     => $totalBudget,
            'totalAllocated'  => $totalAllocated,
            'totalRemaining'  => $totalBudget - $totalAllocated,
        ]);
    }

    /**
     * Sum total hours worked from attendance for a user in a specific month/year.
     */
    private function calculateMonthlyHours(AttendanceRepository $repo, User $user, int $month, int $year): float
    {
        $monthStart = new \DateTime("$year-" . str_pad((string) $month, 2, '0', STR_PAD_LEFT) . "-01");
        $monthEnd   = (clone $monthStart)->modify('+1 month');

        $records = $repo->createQueryBuilder('a')
            ->where('a.user = :user')
            ->andWhere('a.date >= :monthStart')
            ->andWhere('a.date < :monthEnd')
            ->setParameter('user', $user)
            ->setParameter('monthStart', $monthStart)
            ->setParameter('monthEnd', $monthEnd)
            ->getQuery()
            ->getResult();

        $total = 0.0;
        foreach ($records as $att) {
            $checkIn = $att->getCheckIn();
            $checkOut = $att->getCheckOut();
            if ($checkIn && $checkOut) {
                $diff = $checkOut->diff($checkIn);
                $hours = $diff->h + ($diff->i / 60);
                $total += $hours;
            }
        }

        return $total;
    }
}
