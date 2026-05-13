<?php

namespace App\Service;

use App\Entity\Project;

class ProjectRiskService
{
    public function buildForecast(Project $project, float $lat = 36.8, float $lon = 10.18, string $country = 'TN', int $windowDays = 30): array
    {
        $windowDays = max(1, min(90, $windowDays));

        $taskStats = $this->buildTaskStats($project, $windowDays);
        $deadlineScore = $this->calculateDeadlineRiskScore($project->getDeadline());
        $reviewBottleneckScore = $this->calculateReviewBottleneckScore($taskStats['in_review_tasks']);
        $overdueScore = $this->calculateOverdueRiskScore($taskStats['overdue_tasks']);
        $backlogScore = $this->calculateBacklogRiskScore($taskStats['completion_percent']);
        $throughputScore = $this->calculateThroughputRiskScore($taskStats['done_in_window'], $taskStats['new_in_window']);

        $riskScore = min(100, $deadlineScore + $reviewBottleneckScore + $overdueScore + $backlogScore + $throughputScore);
        $riskLevel = $this->mapRiskLevel($riskScore);

        return [
            'project_id' => $project->getId(),
            'project_name' => $project->getName(),
            'intelligence_model' => 'delivery-health-v1',
            'risk_score' => $riskScore,
            'risk_level' => $riskLevel,
            'window_days' => $windowDays,
            'signals' => [
                'deadline_score' => $deadlineScore,
                'review_bottleneck_score' => $reviewBottleneckScore,
                'overdue_score' => $overdueScore,
                'backlog_score' => $backlogScore,
                'throughput_score' => $throughputScore,
            ],
            'task_stats' => $taskStats,
            'recommendations' => $this->buildRecommendations($deadlineScore, $reviewBottleneckScore, $overdueScore, $backlogScore, $throughputScore, $riskLevel),
            'generated_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];
    }

    private function buildTaskStats(Project $project, int $windowDays): array
    {
        $total = 0;
        $completed = 0;
        $overdue = 0;
        $inReview = 0;
        $newInWindow = 0;
        $doneInWindow = 0;
        $today = new \DateTimeImmutable('today');
        $windowStart = $today->modify(sprintf('-%d days', $windowDays));

        foreach ($project->getTasks() as $task) {
            $total++;
            $status = strtoupper((string) $task->getStatus());
            $isDone = in_array($status, ['DONE', 'COMPLETED', 'APPROVED'], true);
            if ($isDone) {
                $completed++;
            }

            if (in_array($status, ['IN_REVIEW', 'REVIEW', 'PENDING_REVIEW'], true)) {
                $inReview++;
            }

            $dueDate = $task->getDueDate();
            if (!$isDone && $dueDate instanceof \DateTimeInterface && $dueDate < $today) {
                $overdue++;
            }

            $createdAt = $task->getCreatedAt();
            if ($createdAt instanceof \DateTimeInterface && $createdAt >= $windowStart) {
                $newInWindow++;
            }
            if ($isDone && $createdAt instanceof \DateTimeInterface && $createdAt >= $windowStart) {
                $doneInWindow++;
            }
        }

        $completionPercent = $total > 0 ? (int) round(($completed / $total) * 100) : 100;

        return [
            'total_tasks' => $total,
            'completed_tasks' => $completed,
            'overdue_tasks' => $overdue,
            'in_review_tasks' => $inReview,
            'new_in_window' => $newInWindow,
            'done_in_window' => $doneInWindow,
            'completion_percent' => $completionPercent,
        ];
    }

    private function calculateDeadlineRiskScore(?\DateTimeInterface $deadline): int
    {
        if (!$deadline) {
            return 5;
        }

        $today = new \DateTimeImmutable('today');
        $deadlineDate = $deadline instanceof \DateTimeImmutable ? $deadline : \DateTimeImmutable::createFromMutable(\DateTime::createFromInterface($deadline));
        $daysLeft = (int) $today->diff($deadlineDate)->format('%r%a');

        return match (true) {
            $daysLeft < 0 => 40,
            $daysLeft <= 3 => 35,
            $daysLeft <= 7 => 25,
            $daysLeft <= 14 => 15,
            $daysLeft <= 30 => 8,
            default => 3,
        };
    }

    private function calculateReviewBottleneckScore(int $inReviewTasks): int
    {
        return match (true) {
            $inReviewTasks >= 8 => 15,
            $inReviewTasks >= 5 => 10,
            $inReviewTasks >= 3 => 6,
            $inReviewTasks >= 1 => 3,
            default => 1,
        };
    }

    private function calculateOverdueRiskScore(int $overdueTasks): int
    {
        return match (true) {
            $overdueTasks >= 10 => 20,
            $overdueTasks >= 6 => 15,
            $overdueTasks >= 3 => 10,
            $overdueTasks >= 1 => 5,
            default => 1,
        };
    }

    private function calculateBacklogRiskScore(int $completionPercent): int
    {
        return match (true) {
            $completionPercent < 25 => 20,
            $completionPercent < 40 => 15,
            $completionPercent < 60 => 10,
            $completionPercent < 80 => 5,
            default => 2,
        };
    }

    private function calculateThroughputRiskScore(int $doneInWindow, int $newInWindow): int
    {
        if ($newInWindow <= 0) {
            return 2;
        }

        $ratio = $doneInWindow / $newInWindow;
        return match (true) {
            $ratio < 0.3 => 15,
            $ratio < 0.5 => 10,
            $ratio < 0.8 => 6,
            default => 2,
        };
    }

    private function mapRiskLevel(int $riskScore): string
    {
        return match (true) {
            $riskScore >= 70 => 'HIGH',
            $riskScore >= 40 => 'MEDIUM',
            default => 'LOW',
        };
    }

    private function buildRecommendations(int $deadlineScore, int $reviewBottleneckScore, int $overdueScore, int $backlogScore, int $throughputScore, string $riskLevel): array
    {
        $recommendations = [];

        if ($deadlineScore >= 25) {
            $recommendations[] = 'Review critical path and consider moving non-critical tasks to a buffer sprint.';
        }
        if ($reviewBottleneckScore >= 10) {
            $recommendations[] = 'Reduce review bottlenecks by assigning a backup reviewer for pending tasks.';
        }
        if ($overdueScore >= 10) {
            $recommendations[] = 'Create an overdue recovery sprint and lock scope until critical tasks are closed.';
        }
        if ($backlogScore >= 10) {
            $recommendations[] = 'Increase review cadence and rebalance task ownership this week.';
        }
        if ($throughputScore >= 10) {
            $recommendations[] = 'Throughput is lagging behind incoming work. Pause new intake or add temporary capacity.';
        }

        if (empty($recommendations)) {
            $recommendations[] = 'Current project risk is stable. Keep weekly monitoring active.';
        }

        if ($riskLevel === 'HIGH') {
            $recommendations[] = 'Escalate project risk to stakeholders with a mitigation owner per signal.';
        }

        return $recommendations;
    }
}
