<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

use App\Repository\TaskRepository;
use App\Entity\Trait\TimestampTrait;
use App\Entity\Trait\BlameableTrait;

#[ORM\Entity(repositoryClass: TaskRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'tasks')]
class Task
{
    use TimestampTrait;
    use BlameableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(int $id): self
    {
        $this->id = $id;
        return $this;
    }

    #[ORM\ManyToOne(targetEntity: Project::class, inversedBy: 'tasks')]
    #[ORM\JoinColumn(name: 'project_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Project $project = null;

    public function getProject(): ?Project
    {
        return $this->project;
    }

    public function setProject(?Project $project): self
    {
        $this->project = $project;
        return $this;
    }

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'assigned_to_id', referencedColumnName: 'id')]
    private ?User $assignedTo = null;

    public function getAssignedTo(): ?User
    {
        return $this->assignedTo;
    }

    public function setAssignedTo(?User $assignedTo): self
    {
        $this->assignedTo = $assignedTo;
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: false)]
    private ?string $title = null;

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;
        return $this;
    }

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $status = null;

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(?string $status): self
    {
        $this->status = $status;
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $priority = null;

    public function getPriority(): ?string
    {
        return $this->priority;
    }

    public function setPriority(?string $priority): self
    {
        $this->priority = $priority;
        return $this;
    }

    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTimeInterface $due_date = null;

    public function getDue_date(): ?\DateTimeInterface
    {
        return $this->due_date;
    }

    public function getDueDate(): ?\DateTimeInterface
    {
        return $this->getDue_date();
    }

    public function setDue_date(?\DateTimeInterface $due_date): self
    {
        $this->due_date = $due_date;
        return $this;
    }

    public function setDueDate(?\DateTimeInterface $due_date): self
    {
        return $this->setDue_date($due_date);
    }
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $submission_text = null;

    public function getSubmission_text(): ?string
    {
        return $this->submission_text;
    }

    public function getSubmissionText(): ?string
    {
        return $this->getSubmission_text();
    }

    public function setSubmission_text(?string $submission_text): self
    {
        $this->submission_text = $submission_text;
        return $this;
    }

    public function setSubmissionText(?string $submission_text): self
    {
        return $this->setSubmission_text($submission_text);
    }

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $submission_file = null;

    public function getSubmission_file(): ?string
    {
        return $this->submission_file;
    }

    public function getSubmissionFile(): ?string
    {
        return $this->getSubmission_file();
    }

    public function setSubmission_file(?string $submission_file): self
    {
        $this->submission_file = $submission_file;
        return $this;
    }

    public function setSubmissionFile(?string $submission_file): self
    {
        return $this->setSubmission_file($submission_file);
    }

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $review_status = null;

    public function getReview_status(): ?string
    {
        return $this->review_status;
    }

    public function getReviewStatus(): ?string
    {
        return $this->getReview_status();
    }

    public function setReview_status(?string $review_status): self
    {
        $this->review_status = $review_status;
        return $this;
    }

    public function setReviewStatus(?string $review_status): self
    {
        return $this->setReview_status($review_status);
    }

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $review_rating = null;

    public function getReview_rating(): ?int
    {
        return $this->review_rating;
    }

    public function getReviewRating(): ?int
    {
        return $this->getReview_rating();
    }

    public function setReview_rating(?int $review_rating): self
    {
        $this->review_rating = $review_rating;
        return $this;
    }

    public function setReviewRating(?int $review_rating): self
    {
        return $this->setReview_rating($review_rating);
    }

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $review_feedback = null;

    public function getReview_feedback(): ?string
    {
        return $this->review_feedback;
    }

    public function getReviewFeedback(): ?string
    {
        return $this->getReview_feedback();
    }

    public function setReview_feedback(?string $review_feedback): self
    {
        $this->review_feedback = $review_feedback;
        return $this;
    }

    public function setReviewFeedback(?string $review_feedback): self
    {
        return $this->setReview_feedback($review_feedback);
    }

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $review_date = null;

    public function getReview_date(): ?\DateTimeInterface
    {
        return $this->review_date;
    }

    public function getReviewDate(): ?\DateTimeInterface
    {
        return $this->getReview_date();
    }

    /** @internal Timestamp — set once */
    public function initReview_date(?\DateTimeInterface $review_date): self
    {
        $this->review_date = $review_date;
        return $this;
    }

    public function initReviewDate(?\DateTimeInterface $review_date): self
    {
        return $this->initReview_date($review_date);
    }

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $github_issue_number = null;

    public function getGithubIssueNumber(): ?int
    {
        return $this->github_issue_number;
    }

    public function setGithubIssueNumber(?int $github_issue_number): self
    {
        $this->github_issue_number = $github_issue_number;
        return $this;
    }

    #[ORM\Column(type: 'string', length: 512, nullable: true)]
    private ?string $github_issue_url = null;

    public function getGithubIssueUrl(): ?string
    {
        return $this->github_issue_url;
    }

    public function setGithubIssueUrl(?string $github_issue_url): self
    {
        $this->github_issue_url = $github_issue_url;
        return $this;
    }

}
