<?php

namespace App\Service;

use App\Entity\Notification;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

class NotificationService
{
    public function __construct(private EntityManagerInterface $em) {}

    public function notify(
        User $user,
        string $type,
        string $title,
        string $body,
        ?int $referenceId = null,
        ?string $referenceType = null
    ): Notification {
        $n = new Notification();
        $n->setUser($user);
        $n->setType($type);
        $n->setTitle($title);
        $n->setBody($body);
        $n->setReferenceId($referenceId);
        $n->setReferenceType($referenceType);
        $n->setIsRead(false);
        $n->initCreatedAt();

        $this->em->persist($n);
        $this->em->flush();

        return $n;
    }

    public function leaveApproved(User $user, int $leaveId, string $type): void
    {
        $this->notify($user, 'LEAVE', 'Leave Approved', "Your {$type} leave request has been approved.", $leaveId, 'Leave');
    }

    public function leaveRejected(User $user, int $leaveId, string $type, string $reason): void
    {
        $this->notify($user, 'LEAVE', 'Leave Rejected', "Your {$type} leave request was rejected: {$reason}", $leaveId, 'Leave');
    }

    public function payrollGenerated(User $user, int $payrollId, string $period, float $netSalary): void
    {
        $this->notify($user, 'PAYROLL', 'Payroll Generated', sprintf('Your payroll for %s has been generated. Net: %.2f', $period, $netSalary), $payrollId, 'Payroll');
    }

    public function contractSigned(User $user, int $contractId): void
    {
        $this->notify($user, 'CONTRACT', 'Contract Signed', 'Your contract has been signed successfully.', $contractId, 'Contract');
    }

    public function trainingCompleted(User $user, int $courseId, string $courseTitle, float $score): void
    {
        $this->notify($user, 'TRAINING', 'Training Completed', sprintf('You completed "%s" with a score of %.0f%%.', $courseTitle, $score), $courseId, 'TrainingCourse');
    }

    public function taskAssigned(User $user, int $taskId, string $taskTitle): void
    {
        $this->notify($user, 'TASK', 'New Task Assigned', "You've been assigned: {$taskTitle}", $taskId, 'Task');
    }

    public function interviewScheduled(User $user, int $interviewId, string $position): void
    {
        $this->notify($user, 'INTERVIEW', 'Interview Scheduled', "An interview has been scheduled for: {$position}", $interviewId, 'Interview');
    }

    public function interviewAccepted(User $user, int $interviewId, string $position, ?int $contractId = null): void
    {
        $body = "Congratulations! Your interview for \"{$position}\" has been accepted.";
        if ($contractId) {
            $body .= " A draft contract (#$contractId) is ready for your review.";
        }
        $this->notify($user, 'INTERVIEW', 'Interview Accepted', $body, $interviewId, 'Interview');
    }
}
