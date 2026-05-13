<?php

namespace App\Tests\Service;

use App\Entity\TrainingCourse;
use App\Service\TrainingCourseManager;
use PHPUnit\Framework\TestCase;

class TrainingCourseManagerTest extends TestCase
{
    public function testValidTrainingCourse(): void
    {
        $course = new TrainingCourse();
        $course->setTitle('PHP & Symfony Fundamentals');
        $course->setDuration_hours(20.0);
        $course->setDifficulty('intermediate');

        $manager = new TrainingCourseManager();
        $this->assertTrue($manager->validate($course));
    }

    public function testTrainingCourseWithoutTitle(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Le titre de la formation est obligatoire');

        $course = new TrainingCourse();
        $course->setDuration_hours(10.0);

        $manager = new TrainingCourseManager();
        $manager->validate($course);
    }

    public function testTrainingCourseWithZeroDuration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('La durée de la formation doit être supérieure à zéro');

        $course = new TrainingCourse();
        $course->setTitle('PHP Basics');
        $course->setDuration_hours(0);

        $manager = new TrainingCourseManager();
        $manager->validate($course);
    }

    public function testTrainingCourseWithInvalidDifficulty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('La difficulté doit être : beginner, intermediate ou advanced');

        $course = new TrainingCourse();
        $course->setTitle('PHP Basics');
        $course->setDuration_hours(5.0);
        $course->setDifficulty('expert');

        $manager = new TrainingCourseManager();
        $manager->validate($course);
    }

    public function testTrainingCourseWithNegativeDuration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('La durée de la formation doit être supérieure à zéro');

        $course = new TrainingCourse();
        $course->setTitle('PHP Advanced');
        $course->setDuration_hours(-5.0);

        $manager = new TrainingCourseManager();
        $manager->validate($course);
    }

    public function testTrainingCourseWithBeginnerDifficulty(): void
    {
        $course = new TrainingCourse();
        $course->setTitle('HTML & CSS Basics');
        $course->setDuration_hours(8.0);
        $course->setDifficulty('beginner');

        $manager = new TrainingCourseManager();
        $this->assertTrue($manager->validate($course));
    }

    public function testTrainingCourseWithAdvancedDifficulty(): void
    {
        $course = new TrainingCourse();
        $course->setTitle('Microservices Architecture');
        $course->setDuration_hours(40.0);
        $course->setDifficulty('advanced');

        $manager = new TrainingCourseManager();
        $this->assertTrue($manager->validate($course));
    }

    public function testTrainingCourseWithNullDifficultyIsValid(): void
    {
        $course = new TrainingCourse();
        $course->setTitle('General Introduction');
        $course->setDuration_hours(2.0);

        $manager = new TrainingCourseManager();
        $this->assertTrue($manager->validate($course));
    }
}
