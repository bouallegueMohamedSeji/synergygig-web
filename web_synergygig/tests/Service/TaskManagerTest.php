<?php

namespace App\Tests\Service;

use App\Entity\Task;
use App\Service\TaskManager;
use PHPUnit\Framework\TestCase;

class TaskManagerTest extends TestCase
{
    public function testValidTask(): void
    {
        $task = new Task();
        $task->setTitle('Implement login feature');
        $task->setPriority('high');
        $task->setDue_date(new \DateTime('+7 days'));

        $manager = new TaskManager();
        $this->assertTrue($manager->validate($task));
    }

    public function testTaskWithoutTitle(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Le titre de la tâche est obligatoire');

        $task = new Task();
        $task->setPriority('medium');

        $manager = new TaskManager();
        $manager->validate($task);
    }

    public function testTaskWithInvalidPriority(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('La priorité doit être : low, medium ou high');

        $task = new Task();
        $task->setTitle('Fix bug');
        $task->setPriority('urgent');

        $manager = new TaskManager();
        $manager->validate($task);
    }

    public function testTaskWithPastDueDate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('La date d\'échéance ne peut pas être dans le passé');

        $task = new Task();
        $task->setTitle('Fix bug');
        $task->setPriority('low');
        $task->setDue_date(new \DateTime('2020-01-01'));

        $manager = new TaskManager();
        $manager->validate($task);
    }
}
