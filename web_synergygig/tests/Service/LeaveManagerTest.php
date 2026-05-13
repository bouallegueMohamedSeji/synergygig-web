<?php

namespace App\Tests\Service;

use App\Entity\Leave;
use App\Service\LeaveManager;
use PHPUnit\Framework\TestCase;

class LeaveManagerTest extends TestCase
{
    public function testValidLeave(): void
    {
        $leave = new Leave();
        $leave->setType('annual');
        $leave->setStartDate(new \DateTime('2026-06-01'));
        $leave->setEndDate(new \DateTime('2026-06-05'));
        $leave->setReason('Family vacation');

        $manager = new LeaveManager();
        $this->assertTrue($manager->validate($leave));
    }

    public function testLeaveWithoutType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Le type de congé est obligatoire');

        $leave = new Leave();
        $leave->setStartDate(new \DateTime('2026-06-01'));
        $leave->setEndDate(new \DateTime('2026-06-05'));

        $manager = new LeaveManager();
        $manager->validate($leave);
    }

    public function testLeaveEndDateBeforeStartDate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('La date de fin doit être postérieure à la date de début');

        $leave = new Leave();
        $leave->setType('sick');
        $leave->setStartDate(new \DateTime('2026-06-10'));
        $leave->setEndDate(new \DateTime('2026-06-05'));

        $manager = new LeaveManager();
        $manager->validate($leave);
    }

    public function testLeaveStartDateEqualsEndDate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('La date de fin doit être postérieure à la date de début');

        $leave = new Leave();
        $leave->setType('sick');
        $leave->setStartDate(new \DateTime('2026-06-01'));
        $leave->setEndDate(new \DateTime('2026-06-01'));

        $manager = new LeaveManager();
        $manager->validate($leave);
    }
}
