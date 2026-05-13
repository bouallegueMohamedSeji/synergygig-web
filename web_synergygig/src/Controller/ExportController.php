<?php

namespace App\Controller;

use App\Repository\AttendanceRepository;
use App\Repository\LeaveRepository;
use App\Repository\PayrollRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/export')]
class ExportController extends AbstractController
{
    /* ─── Payroll CSV ─── */

    #[Route('/payroll/csv', name: 'app_export_payroll_csv')]
    #[IsGranted('ROLE_HR')]
    public function payrollCsv(PayrollRepository $repo): StreamedResponse
    {
        $records = $repo->findBy([], ['year' => 'DESC', 'month' => 'DESC']);

        $response = new StreamedResponse(function () use ($records) {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                throw new \RuntimeException('Unable to open output stream.');
            }
            fputcsv($handle, ['ID', 'Employee', 'Month', 'Year', 'Base Salary', 'Bonus', 'Deductions', 'Net Salary', 'Hours', 'Status', 'Generated']);

            foreach ($records as $p) {
                fputcsv($handle, [
                    $p->getId(),
                    $p->getUser() ? $p->getUser()->getFirstName() . ' ' . $p->getUser()->getLastName() : 'N/A',
                    $p->getMonth(),
                    $p->getYear(),
                    $p->getBaseSalary(),
                    $p->getBonus(),
                    $p->getDeductions(),
                    $p->getNetSalary(),
                    $p->getTotalHoursWorked(),
                    $p->getStatus(),
                    $p->getGeneratedAt()?->format('Y-m-d H:i'),
                ]);
            }
            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv');
        $response->headers->set('Content-Disposition', 'attachment; filename="payroll_export_' . date('Y-m-d') . '.csv"');
        return $response;
    }

    /* ─── Attendance CSV ─── */

    #[Route('/attendance/csv', name: 'app_export_attendance_csv')]
    #[IsGranted('ROLE_HR')]
    public function attendanceCsv(AttendanceRepository $repo, Request $request): StreamedResponse
    {
        $records = $repo->findBy([], ['date' => 'DESC']);

        $response = new StreamedResponse(function () use ($records) {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                throw new \RuntimeException('Unable to open output stream.');
            }
            fputcsv($handle, ['ID', 'Employee', 'Date', 'Check-In', 'Check-Out', 'Status', 'Hours']);

            foreach ($records as $a) {
                $hours = '';
                if ($a->getCheckIn() && $a->getCheckOut()) {
                    $diff = (new \DateTime($a->getCheckOut()->format('H:i:s')))->diff(new \DateTime($a->getCheckIn()->format('H:i:s')));
                    $hours = round($diff->h + $diff->i / 60, 2);
                }

                fputcsv($handle, [
                    $a->getId(),
                    $a->getUser() ? $a->getUser()->getFirstName() . ' ' . $a->getUser()->getLastName() : 'N/A',
                    $a->getDate()?->format('Y-m-d'),
                    $a->getCheckIn()?->format('H:i'),
                    $a->getCheckOut()?->format('H:i'),
                    $a->getStatus(),
                    $hours,
                ]);
            }
            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv');
        $response->headers->set('Content-Disposition', 'attachment; filename="attendance_export_' . date('Y-m-d') . '.csv"');
        return $response;
    }

    /* ─── Leave CSV ─── */

    #[Route('/leave/csv', name: 'app_export_leave_csv')]
    #[IsGranted('ROLE_HR')]
    public function leaveCsv(LeaveRepository $repo): StreamedResponse
    {
        $records = $repo->findBy([], ['start_date' => 'DESC']);

        $response = new StreamedResponse(function () use ($records) {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                throw new \RuntimeException('Unable to open output stream.');
            }
            fputcsv($handle, ['ID', 'Employee', 'Type', 'Start Date', 'End Date', 'Days', 'Status', 'Reason', 'Rejection Reason']);

            foreach ($records as $l) {
                $days = '';
                if ($l->getStartDate() && $l->getEndDate()) {
                    $days = $l->getStartDate()->diff($l->getEndDate())->days + 1;
                }

                fputcsv($handle, [
                    $l->getId(),
                    $l->getUser() ? $l->getUser()->getFirstName() . ' ' . $l->getUser()->getLastName() : 'N/A',
                    $l->getType(),
                    $l->getStartDate()?->format('Y-m-d'),
                    $l->getEndDate()?->format('Y-m-d'),
                    $days,
                    $l->getStatus(),
                    $l->getReason(),
                    $l->getRejectionReason(),
                ]);
            }
            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv');
        $response->headers->set('Content-Disposition', 'attachment; filename="leave_export_' . date('Y-m-d') . '.csv"');
        return $response;
    }

    /* ─── Payroll PDF (HTML-based printable) ─── */

    #[Route('/payroll/{id}/pdf', name: 'app_export_payroll_pdf', requirements: ['id' => '\d+'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function payrollPdf(int $id, PayrollRepository $repo): Response
    {
        $payroll = $repo->find($id);
        if (!$payroll) {
            throw $this->createNotFoundException('Payroll not found.');
        }

        // HR/Admin can view any payslip; everyone else can only view their own
        if (!$this->isGranted('ROLE_HR')) {
            if ($payroll->getUser()?->getId() !== $this->getUser()?->getId()) {
                throw $this->createAccessDeniedException('You can only view your own payslip.');
            }
        }

        return $this->render('export/payroll_pdf.html.twig', [
            'payroll' => $payroll,
        ]);
    }
}
