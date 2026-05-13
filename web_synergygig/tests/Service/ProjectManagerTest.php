<?php

namespace App\Tests\Service;

use App\Entity\Project;
use App\Entity\Task;
use App\Service\ProjectManager;
use App\Service\TaskManager;
use PHPUnit\Framework\TestCase;

class ProjectManagerTest extends TestCase
{
    public function testValidProject(): void
    {
        $project = new Project();
        $project->setName('SynergyGig Platform');
        $project->setStartDate(new \DateTime('2026-01-01'));
        $project->setDeadline(new \DateTime('2026-12-31'));

        $manager = new ProjectManager();
        $this->assertTrue($manager->validate($project));
    }

    public function testProjectWithoutName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Le nom du projet est obligatoire');

        $project = new Project();
        $project->setStartDate(new \DateTime('2026-01-01'));
        $project->setDeadline(new \DateTime('2026-12-31'));

        $manager = new ProjectManager();
        $manager->validate($project);
    }

    public function testProjectDeadlineBeforeStartDate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('La date limite doit être postérieure à la date de début');

        $project = new Project();
        $project->setName('SynergyGig Platform');
        $project->setStartDate(new \DateTime('2026-12-31'));
        $project->setDeadline(new \DateTime('2026-01-01'));

        $manager = new ProjectManager();
        $manager->validate($project);
    }

    public function testProjectWithEmptyName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Le nom du projet est obligatoire');

        $project = new Project();
        $project->setName('   ');

        $manager = new ProjectManager();
        $manager->validate($project);
    }

    public function testProjectWithoutDatesIsValid(): void
    {
        $project = new Project();
        $project->setName('Projet Interne');

        $manager = new ProjectManager();
        $this->assertTrue($manager->validate($project));
    }

    // --- Task tests (Projects module includes Task management) ---

    public function testValidTask(): void
    {
        $task = new Task();
        $task->setTitle('Développer le module RH');
        $task->setPriority('high');
        $task->setDue_date(new \DateTime('+14 days'));

        $manager = new TaskManager();
        $this->assertTrue($manager->validate($task));
    }

    public function testTaskWithoutTitle(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Le titre de la tâche est obligatoire');

        $task = new Task();
        $task->setPriority('low');

        $manager = new TaskManager();
        $manager->validate($task);
    }

    public function testTaskWithInvalidPriority(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('La priorité doit être : low, medium ou high');

        $task = new Task();
        $task->setTitle('Corriger bug critique');
        $task->setPriority('critical');

        $manager = new TaskManager();
        $manager->validate($task);
    }
}
