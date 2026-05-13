<?php

namespace App\Service;

use App\Entity\TrainingCourse;

class TrainingCourseManager
{
    private const VALID_DIFFICULTIES = ['beginner', 'intermediate', 'advanced'];

    public function validate(TrainingCourse $course): bool
    {
        if (empty(trim($course->getTitle() ?? ''))) {
            throw new \InvalidArgumentException('Le titre de la formation est obligatoire');
        }

        if ($course->getDurationHours() !== null && $course->getDurationHours() <= 0) {
            throw new \InvalidArgumentException('La durée de la formation doit être supérieure à zéro');
        }

        if ($course->getDifficulty() !== null && !in_array($course->getDifficulty(), self::VALID_DIFFICULTIES, true)) {
            throw new \InvalidArgumentException('La difficulté doit être : beginner, intermediate ou advanced');
        }

        return true;
    }
}
