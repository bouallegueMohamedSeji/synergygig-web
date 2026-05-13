<?php

namespace App\Service;

use App\Entity\Task;

class TaskManager
{
    private const VALID_PRIORITIES = ['low', 'medium', 'high'];

    public function validate(Task $task): bool
    {
        if (empty(trim($task->getTitle() ?? ''))) {
            throw new \InvalidArgumentException('Le titre de la tâche est obligatoire');
        }

        if ($task->getPriority() !== null && !in_array($task->getPriority(), self::VALID_PRIORITIES, true)) {
            throw new \InvalidArgumentException('La priorité doit être : low, medium ou high');
        }

        if ($task->getDueDate() !== null) {
            $today = new \DateTime('today');
            if ($task->getDueDate() < $today) {
                throw new \InvalidArgumentException('La date d\'échéance ne peut pas être dans le passé');
            }
        }

        return true;
    }
}
