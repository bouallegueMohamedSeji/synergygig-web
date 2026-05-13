<?php

namespace App\Tests\Service;

use App\Entity\Leave;
use App\Entity\Payroll;
use App\Entity\Attendance;
use App\Service\HRManager;
use PHPUnit\Framework\TestCase;

class HRManagerTest extends TestCase
{
    // ─── Leave Tests ──────────────────────────────────────────────

    public function testValidAnnualLeave(): void
    {
        $leave = new Leave();
        $leave->setType('annual');
        $leave->setStartDate(new \DateTime('2026-07-01'));
        $leave->setEndDate(new \DateTime('2026-07-14'));

        $manager = new HRManager();
        $this->assertTrue($manager->validateLeave($leave));
    }

    public function testLeaveWithInvalidType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Type de congé invalide');

        $leave = new Leave();
        $leave->setType('sabbatical');
        $leave->setStartDate(new \DateTime('2026-07-01'));
        $leave->setEndDate(new \DateTime('2026-07-10'));

        $manager = new HRManager();
        $manager->validateLeave($leave);
    }

    public function testLeaveEndDateNotAfterStartDate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('La date de fin doit être postérieure à la date de début');

        $leave = new Leave();
        $leave->setType('sick');
        $leave->setStartDate(new \DateTime('2026-07-10'));
        $leave->setEndDate(new \DateTime('2026-07-05'));

        $manager = new HRManager();
        $manager->validateLeave($leave);
    }

    // ─── Payroll Tests ────────────────────────────────────────────

    public function testValidPayroll(): void
    {
        $payroll = new Payroll();
        $payroll->setBaseSalary('3200.00');
        $payroll->setBonus('500.00');
        $payroll->setMonth(6);
        $payroll->setYear(2026);

        $manager = new HRManager();
        $this->assertTrue($manager->validatePayroll($payroll));
    }

    public function testPayrollWithNegativeBaseSalary(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Le salaire de base doit être supérieur à zéro');

        $payroll = new Payroll();
        $payroll->setBaseSalary('-500.00');
        $payroll->setMonth(6);
        $payroll->setYear(2026);

        $manager = new HRManager();
        $manager->validatePayroll($payroll);
    }

    public function testPayrollWithMonthZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Le mois doit être compris entre 1 et 12');

        $payroll = new Payroll();
        $payroll->setBaseSalary('2000.00');
        $payroll->setMonth(0);
        $payroll->setYear(2026);

        $manager = new HRManager();
        $manager->validatePayroll($payroll);
    }

    // ─── Attendance Tests ─────────────────────────────────────────

    public function testValidAttendance(): void
    {
        $attendance = new Attendance();
        $attendance->setDate(new \DateTime('2026-05-06'));
        $attendance->setStatus('present');
        $attendance->setCheckIn(new \DateTime('08:30'));
        $attendance->setCheckOut(new \DateTime('17:30'));

        $manager = new HRManager();
        $this->assertTrue($manager->validateAttendance($attendance));
    }

    public function testAttendanceWithoutDate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('La date de présence est obligatoire');

        $attendance = new Attendance();
        $attendance->setStatus('present');

        $manager = new HRManager();
        $manager->validateAttendance($attendance);
    }

    public function testAttendanceCheckOutBeforeCheckIn(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('L\'heure de sortie doit être après l\'heure d\'entrée');

        $attendance = new Attendance();
        $attendance->setDate(new \DateTime('2026-05-06'));
        $attendance->setStatus('present');
        $attendance->setCheckIn(new \DateTime('17:00'));
        $attendance->setCheckOut(new \DateTime('08:00'));

        $manager = new HRManager();
        $manager->validateAttendance($attendance);
    }
}
