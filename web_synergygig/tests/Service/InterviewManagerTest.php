<?php

namespace App\Tests\Service;

use App\Entity\Interview;
use App\Service\InterviewManager;
use PHPUnit\Framework\TestCase;

class InterviewManagerTest extends TestCase
{
    public function testValidInterview(): void
    {
        $interview = new Interview();
        $interview->initDateTime(new \DateTime('+3 days'));
        $interview->setStatus('scheduled');
        $interview->setMeetLink('https://meet.google.com/abc-defg-hij');

        $manager = new InterviewManager();
        $this->assertTrue($manager->validate($interview));
    }

    public function testInterviewWithoutDateTime(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('La date et l\'heure de l\'entretien sont obligatoires');

        $interview = new Interview();
        $interview->setStatus('scheduled');

        $manager = new InterviewManager();
        $manager->validate($interview);
    }

    public function testInterviewWithPastDateTime(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('La date de l\'entretien ne peut pas être dans le passé');

        $interview = new Interview();
        $interview->initDateTime(new \DateTime('2020-01-01 10:00:00'));
        $interview->setStatus('scheduled');

        $manager = new InterviewManager();
        $manager->validate($interview);
    }

    public function testInterviewWithInvalidStatus(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Le statut doit être : scheduled, completed ou cancelled');

        $interview = new Interview();
        $interview->initDateTime(new \DateTime('+3 days'));
        $interview->setStatus('pending_approval');

        $manager = new InterviewManager();
        $manager->validate($interview);
    }
}
