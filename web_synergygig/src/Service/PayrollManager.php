<?php

namespace App\Service;

use App\Entity\Payroll;

class PayrollManager
{
    public function validate(Payroll $payroll): bool
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
}
