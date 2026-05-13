<?php

namespace App\Tests\Service;

use App\Entity\Payroll;
use App\Service\PayrollManager;
use PHPUnit\Framework\TestCase;

class PayrollManagerTest extends TestCase
{
    public function testValidPayroll(): void
    {
        $payroll = new Payroll();
        $payroll->setBaseSalary('2500.00');
        $payroll->setBonus('300.00');
        $payroll->setMonth(5);
        $payroll->setYear(2026);

        $manager = new PayrollManager();
        $this->assertTrue($manager->validate($payroll));
    }

    public function testPayrollWithZeroBaseSalary(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Le salaire de base doit être supérieur à zéro');

        $payroll = new Payroll();
        $payroll->setBaseSalary('0');
        $payroll->setMonth(5);
        $payroll->setYear(2026);

        $manager = new PayrollManager();
        $manager->validate($payroll);
    }

    public function testPayrollWithInvalidMonth(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Le mois doit être compris entre 1 et 12');

        $payroll = new Payroll();
        $payroll->setBaseSalary('2500.00');
        $payroll->setMonth(13);
        $payroll->setYear(2026);

        $manager = new PayrollManager();
        $manager->validate($payroll);
    }

    public function testPayrollWithNegativeBonus(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Le bonus ne peut pas être négatif');

        $payroll = new Payroll();
        $payroll->setBaseSalary('2500.00');
        $payroll->setBonus('-100.00');
        $payroll->setMonth(5);
        $payroll->setYear(2026);

        $manager = new PayrollManager();
        $manager->validate($payroll);
    }
}
