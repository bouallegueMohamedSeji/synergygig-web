<?php

namespace App\Service;

use App\Entity\Leave;
use App\Entity\Payroll;
use App\Entity\Attendance;

class HRManager
{
    private const VALID_LEAVE_TYPES = ['annual', 'sick', 'maternity', 'paternity', 'unpaid'];
    private const VALID_ATTENDANCE_STATUSES = ['present', 'absent', 'late', 'half_day'];

    public function validateLeave(Leave $leave): bool
    {
        if (empty(trim($leave->getType() ?? ''))) {
            throw new \InvalidArgumentException('Le type de congé est obligatoire');
        }

        if (!in_array($leave->getType(), self::VALID_LEAVE_TYPES, true)) {
            throw new \InvalidArgumentException('Type de congé invalide : annual, sick, maternity, paternity ou unpaid');
        }

        if ($leave->getStartDate() === null || $leave->getEndDate() === null) {
            throw new \InvalidArgumentException('Les dates de début et de fin sont obligatoires');
        }

        if ($leave->getEndDate() <= $leave->getStartDate()) {
            throw new \InvalidArgumentException('La date de fin doit être postérieure à la date de début');
        }

        return true;
    }

    public function validatePayroll(Payroll $payroll): bool
    {
        if ($payroll->getBaseSalary() === null || (float) $payroll->getBaseSalary() <= 0) {
            throw new \InvalidArgumentException('Le salaire de base doit être supérieur à zéro');
        }

        $month = $payroll->getMonth();
        if ($month === null || $month < 1 || $month > 12) {
            throw new \InvalidArgumentException('Le mois doit être compris entre 1 et 12');
        }

        if ($payroll->getBonus() !== null && (float) $payroll->getBonus() < 0) {
            throw new \InvalidArgumentException('Le bonus ne peut pas être négatif');
        }

        return true;
    }

    public function validateAttendance(Attendance $attendance): bool
    {
        if ($attendance->getDate() === null) {
            throw new \InvalidArgumentException('La date de présence est obligatoire');
        }

        if (empty(trim($attendance->getStatus() ?? ''))) {
            throw new \InvalidArgumentException('Le statut de présence est obligatoire');
        }

        if (!in_array($attendance->getStatus(), self::VALID_ATTENDANCE_STATUSES, true)) {
            throw new \InvalidArgumentException('Statut invalide : present, absent, late ou half_day');
        }

        if ($attendance->getCheckIn() !== null && $attendance->getCheckOut() !== null) {
            if ($attendance->getCheckOut() <= $attendance->getCheckIn()) {
                throw new \InvalidArgumentException('L\'heure de sortie doit être après l\'heure d\'entrée');
            }
        }

        return true;
    }
}
