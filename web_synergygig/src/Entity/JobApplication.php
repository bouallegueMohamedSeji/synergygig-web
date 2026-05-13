<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

use App\Repository\JobApplicationRepository;

#[ORM\Entity(repositoryClass: JobApplicationRepository::class)]
#[ORM\Table(name: 'job_applications')]
class JobApplication
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /** @var Collection<int, Interview> */
    #[ORM\OneToMany(mappedBy: 'application', targetEntity: Interview::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $interviews;

    public function __construct()
    {
        $this->interviews = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(int $id): self
    {
        $this->id = $id;
        return $this;
    }

    /** @return Collection<int, Interview> */
    public function getInterviews(): Collection { return $this->interviews; }

    #[ORM\ManyToOne(targetEntity: Offer::class, inversedBy: 'jobApplications')]
    #[ORM\JoinColumn(name: 'offer_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Offer $offer = null;

    public function getOffer(): ?Offer
    {
        return $this->offer;
    }

    public function setOffer(?Offer $offer): self
    {
        $this->offer = $offer;
        return $this;
    }

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'applicant_id', referencedColumnName: 'id')]
    private ?User $applicant = null;

    public function getApplicant(): ?User
    {
        return $this->applicant;
    }

    public function setApplicant(?User $applicant): self
    {
        $this->applicant = $applicant;
        return $this;
    }

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $cover_letter = null;

    public function getCover_letter(): ?string
    {
        return $this->cover_letter;
    }

    public function getCoverLetter(): ?string
    {
        return $this->getCover_letter();
    }

    public function setCover_letter(?string $cover_letter): self
    {
        $this->cover_letter = $cover_letter;
        return $this;
    }

    public function setCoverLetter(?string $cover_letter): self
    {
        return $this->setCover_letter($cover_letter);
    }

    #[ORM\Column(type: 'string', nullable: false)]
    private ?string $status = null;

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $ai_score = null;

    public function getAi_score(): ?int
    {
        return $this->ai_score;
    }

    public function getAiScore(): ?int
    {
        return $this->getAi_score();
    }

    public function setAi_score(?int $ai_score): self
    {
        $this->ai_score = $ai_score;
        return $this;
    }

    public function setAiScore(?int $ai_score): self
    {
        return $this->setAi_score($ai_score);
    }

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $ai_feedback = null;

    public function getAi_feedback(): ?string
    {
        return $this->ai_feedback;
    }

    public function getAiFeedback(): ?string
    {
        return $this->getAi_feedback();
    }

    public function setAi_feedback(?string $ai_feedback): self
    {
        $this->ai_feedback = $ai_feedback;
        return $this;
    }

    public function setAiFeedback(?string $ai_feedback): self
    {
        return $this->setAi_feedback($ai_feedback);
    }

    #[ORM\Column(type: 'datetime', nullable: false)]
    private ?\DateTimeInterface $applied_at = null;

    public function getApplied_at(): ?\DateTimeInterface
    {
        return $this->applied_at;
    }

    public function getAppliedAt(): ?\DateTimeInterface
    {
        return $this->getApplied_at();
    }

    /** @internal Timestamp — set once */
    public function initApplied_at(\DateTimeInterface $applied_at): self
    {
        $this->applied_at = $applied_at;
        return $this;
    }

    public function initAppliedAt(\DateTimeInterface $applied_at): self
    {
        return $this->initApplied_at($applied_at);
    }

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $reviewed_at = null;

    public function getReviewed_at(): ?\DateTimeInterface
    {
        return $this->reviewed_at;
    }

    public function getReviewedAt(): ?\DateTimeInterface
    {
        return $this->getReviewed_at();
    }

    /** @internal Timestamp — set once */
    public function initReviewed_at(?\DateTimeInterface $reviewed_at): self
    {
        $this->reviewed_at = $reviewed_at;
        return $this;
    }

    public function initReviewedAt(?\DateTimeInterface $reviewed_at): self
    {
        return $this->initReviewed_at($reviewed_at);
    }

}
