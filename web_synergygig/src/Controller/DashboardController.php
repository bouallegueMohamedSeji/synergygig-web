<?php

namespace App\Controller;

use App\DTO\DepartmentHeadcountDto;
use App\DTO\StatusCountDto;
use App\Repository\UserRepository;
use App\Repository\DepartmentRepository;
use App\Repository\ProjectRepository;
use App\Repository\OfferRepository;
use App\Repository\ContractRepository;
use App\Repository\JobApplicationRepository;
use App\Repository\NotificationRepository;
use App\Repository\PostRepository;
use App\Repository\TaskRepository;
use App\Repository\TrainingCourseRepository;
use App\Repository\InterviewRepository;
use App\Repository\AttendanceRepository;
use App\Repository\LeaveRepository;
use App\Repository\PayrollRepository;
use App\Repository\ChatRoomRepository;
use App\Repository\MessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class DashboardController extends AbstractController
{
    private const QUOTES = [
        ['text' => 'Have a vision. Be demanding.', 'author' => 'Colin Powell'],
        ['text' => 'The only way to do great work is to love what you do.', 'author' => 'Steve Jobs'],
        ['text' => 'Innovation distinguishes between a leader and a follower.', 'author' => 'Steve Jobs'],
        ['text' => 'Success is not final, failure is not fatal: it is the courage to continue that counts.', 'author' => 'Winston Churchill'],
        ['text' => 'The best time to plant a tree was 20 years ago. The second best time is now.', 'author' => 'Chinese Proverb'],
        ['text' => 'Quality is not an act, it is a habit.', 'author' => 'Aristotle'],
        ['text' => 'Alone we can do so little; together we can do so much.', 'author' => 'Helen Keller'],
        ['text' => 'The greatest glory in living lies not in never falling, but in rising every time we fall.', 'author' => 'Nelson Mandela'],
    ];

    #[Route('/dashboard', name: 'app_dashboard')]
    public function index(
        UserRepository $userRepo,
        DepartmentRepository $deptRepo,
        ProjectRepository $projectRepo,
        OfferRepository $offerRepo,
        ContractRepository $contractRepo,
        JobApplicationRepository $appRepo,
        NotificationRepository $notifRepo,
        PostRepository $postRepo,
        TaskRepository $taskRepo,
        TrainingCourseRepository $trainingRepo,
        InterviewRepository $interviewRepo,
        AttendanceRepository $attendanceRepo,
        LeaveRepository $leaveRepo,
        PayrollRepository $payrollRepo,
        ChatRoomRepository $chatRoomRepo,
        MessageRepository $messageRepo,
        EntityManagerInterface $em,
    ): Response {
        $totalUsers = $userRepo->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->where('1 = 1')
            ->getQuery()
            ->getSingleScalarResult();

        $quote = self::QUOTES[array_rand(self::QUOTES)];

        // Chart data: tasks by status
        $taskStatuses = ['TODO', 'IN_PROGRESS', 'IN_REVIEW', 'DONE'];
        $taskChart = array_fill_keys($taskStatuses, 0);
        $taskRows = $taskRepo->createQueryBuilder('t')
            ->select('NEW App\\DTO\\StatusCountDto(t.status, COUNT(t.id))')
            ->groupBy('t.status')
            ->setMaxResults(20)
            ->getQuery()
            ->getResult();
        foreach ($taskRows as $row) {
            if ($row instanceof StatusCountDto && $row->label !== null) {
                $taskChart[$row->label] = $row->total;
            }
        }

        // Chart data: leaves by status
        $leaveStatuses = ['PENDING', 'APPROVED', 'REJECTED'];
        $leaveChart = array_fill_keys($leaveStatuses, 0);
        $leaveRows = $leaveRepo->createQueryBuilder('l')
            ->select('NEW App\\DTO\\StatusCountDto(l.status, COUNT(l.id))')
            ->groupBy('l.status')
            ->setMaxResults(20)
            ->getQuery()
            ->getResult();
        foreach ($leaveRows as $row) {
            if ($row instanceof StatusCountDto && $row->label !== null) {
                $leaveChart[$row->label] = $row->total;
            }
        }

        // Chart data: users by role
        $roles = ['ADMIN', 'HR_MANAGER', 'PROJECT_OWNER', 'EMPLOYEE', 'GIG_WORKER'];
        $roleChart = array_fill_keys($roles, 0);
        $roleRows = $userRepo->createQueryBuilder('u')
            ->select('NEW App\\DTO\\StatusCountDto(u.role, COUNT(u.id))')
            ->groupBy('u.role')
            ->setMaxResults(20)
            ->getQuery()
            ->getResult();
        foreach ($roleRows as $row) {
            if ($row instanceof StatusCountDto && $row->label !== null) {
                $roleChart[$row->label] = $row->total;
            }
        }
        $employees = $roleChart['EMPLOYEE'];
        $gig = $roleChart['GIG_WORKER'];

        // Chart data: offers by status
        $offerStatuses = ['DRAFT', 'OPEN', 'CLOSED', 'CANCELLED'];
        $offerChart = array_fill_keys($offerStatuses, 0);
        $offerRows = $offerRepo->createQueryBuilder('o')
            ->select('NEW App\\DTO\\StatusCountDto(o.status, COUNT(o.id))')
            ->groupBy('o.status')
            ->setMaxResults(20)
            ->getQuery()
            ->getResult();
        foreach ($offerRows as $row) {
            if ($row instanceof StatusCountDto && $row->label !== null) {
                $offerChart[$row->label] = $row->total;
            }
        }

        // Chart data: applications by status
        $appStatuses = ['PENDING', 'REVIEWED', 'ACCEPTED', 'REJECTED'];
        $appChart = array_fill_keys($appStatuses, 0);
        $appRows = $appRepo->createQueryBuilder('a')
            ->select('NEW App\\DTO\\StatusCountDto(a.status, COUNT(a.id))')
            ->groupBy('a.status')
            ->setMaxResults(20)
            ->getQuery()
            ->getResult();
        foreach ($appRows as $row) {
            if ($row instanceof StatusCountDto && $row->label !== null) {
                $appChart[$row->label] = $row->total;
            }
        }

        $interviewPending = $interviewRepo->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->where('i.status = :status')
            ->setParameter('status', 'PENDING')
            ->getQuery()
            ->getSingleScalarResult();

        // Chart data: department headcount (DTO hydration avoids array hydration and N+1 loops)
        $deptChart = [];
        $deptRows = $userRepo->createQueryBuilder('u')
            ->select('NEW App\\DTO\\DepartmentHeadcountDto(d.name, COUNT(DISTINCT u.id))')
            ->innerJoin('u.department', 'd')
            ->groupBy('d.id, d.name')
            ->orderBy('d.name', 'ASC')
            ->setMaxResults(50)
            ->getQuery()
            ->getResult();

        foreach ($deptRows as $row) {
            if ($row instanceof DepartmentHeadcountDto) {
                $deptChart[$row->departmentName] = $row->employeeCount;
            }
        }

        return $this->render('dashboard/index.html.twig', [
            'stats' => [
                'users' => $totalUsers,
                'employees' => $employees,
                'gig_workers' => $gig,
                'interviews' => (int) $interviewPending,
                'departments' => $deptRepo->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->where('1 = 1')
            ->getQuery()
            ->getSingleScalarResult(),
                'projects' => $projectRepo->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('1 = 1')
            ->getQuery()
            ->getSingleScalarResult(),
                'offers' => array_sum($offerChart),
                'offers_open' => $offerChart['OPEN'],
                'offers_draft' => $offerChart['DRAFT'],
                'contracts' => $contractRepo->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('1 = 1')
            ->getQuery()
            ->getSingleScalarResult(),
                'applications' => array_sum($appChart),
                'apps_pending' => $appChart['PENDING'],
                'apps_accepted' => $appChart['ACCEPTED'],
                'tasks' => array_sum($taskChart),
                'training' => $trainingRepo->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('1 = 1')
            ->getQuery()
            ->getSingleScalarResult(),
                'chat_rooms' => $chatRoomRepo->createQueryBuilder('cr')
            ->select('COUNT(cr.id)')
            ->where('1 = 1')
            ->getQuery()
            ->getSingleScalarResult(),
                'messages' => $messageRepo->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('1 = 1')
            ->getQuery()
            ->getSingleScalarResult(),
                'pending_leaves' => $leaveChart['PENDING'],
                'pending_payroll' => $payrollRepo->count(['status' => 'PENDING']),
            ],
            'taskChart' => $taskChart,
            'leaveChart' => $leaveChart,
            'roleChart' => $roleChart,
            'offerChart' => $offerChart,
            'appChart' => $appChart,
            'deptChart' => $deptChart,
            'quote' => $quote,
            'recent_users' => $userRepo->findBy([], ['id' => 'DESC'], 5),
            'recent_departments' => $deptRepo->findBy([], ['id' => 'DESC'], 5),
        ]);
    }
}
