<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

use App\Repository\TrainingCourseRepository;
use App\Entity\Trait\TimestampTrait;

#[ORM\Entity(repositoryClass: TrainingCourseRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'training_course')]
class TrainingCourse
{
    use TimestampTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /** @var Collection<int, TrainingEnrollment> */
    #[ORM\OneToMany(mappedBy: 'course', targetEntity: TrainingEnrollment::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $enrollments;

    /** @var Collection<int, TrainingCertificate> */
    #[ORM\OneToMany(mappedBy: 'course', targetEntity: TrainingCertificate::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $certificates;

    public function __construct()
    {
        $this->enrollments = new ArrayCollection();
        $this->certificates = new ArrayCollection();
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

    /** @return Collection<int, TrainingEnrollment> */
    public function getEnrollments(): Collection { return $this->enrollments; }
    /** @return Collection<int, TrainingCertificate> */
    public function getCertificates(): Collection { return $this->certificates; }

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
    private ?string $category = null;

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function setCategory(?string $category): self
    {
        $this->category = $category;
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $difficulty = null;

    public function getDifficulty(): ?string
    {
        return $this->difficulty;
    }

    public function setDifficulty(?string $difficulty): self
    {
        $this->difficulty = $difficulty;
        return $this;
    }

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $duration_hours = null;

    public function getDuration_hours(): ?float
    {
        return $this->duration_hours;
    }

    public function getDurationHours(): ?float
    {
        return $this->getDuration_hours();
    }

    public function setDuration_hours(?float $duration_hours): self
    {
        $this->duration_hours = $duration_hours;
        return $this;
    }

    public function setDurationHours(?float $duration_hours): self
    {
        return $this->setDuration_hours($duration_hours);
    }

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $instructor_name = null;

    public function getInstructor_name(): ?string
    {
        return $this->instructor_name;
    }

    public function getInstructorName(): ?string
    {
        return $this->getInstructor_name();
    }

    public function setInstructor_name(?string $instructor_name): self
    {
        $this->instructor_name = $instructor_name;
        return $this;
    }

    public function setInstructorName(?string $instructor_name): self
    {
        return $this->setInstructor_name($instructor_name);
    }

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $mega_link = null;

    public function getMega_link(): ?string
    {
        return $this->mega_link;
    }

    public function getMegaLink(): ?string
    {
        return $this->getMega_link();
    }

    public function setMega_link(?string $mega_link): self
    {
        $this->mega_link = $mega_link;
        return $this;
    }

    public function setMegaLink(?string $mega_link): self
    {
        return $this->setMega_link($mega_link);
    }

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $thumbnail_url = null;

    public function getThumbnail_url(): ?string
    {
        return $this->thumbnail_url;
    }

    public function getThumbnailUrl(): ?string
    {
        return $this->getThumbnail_url();
    }

    public function setThumbnail_url(?string $thumbnail_url): self
    {
        $this->thumbnail_url = $thumbnail_url;
        return $this;
    }

    public function setThumbnailUrl(?string $thumbnail_url): self
    {
        return $this->setThumbnail_url($thumbnail_url);
    }

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $max_participants = null;

    public function getMax_participants(): ?int
    {
        return $this->max_participants;
    }

    public function getMaxParticipants(): ?int
    {
        return $this->getMax_participants();
    }

    public function setMax_participants(?int $max_participants): self
    {
        $this->max_participants = $max_participants;
        return $this;
    }

    public function setMaxParticipants(?int $max_participants): self
    {
        return $this->setMax_participants($max_participants);
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

    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTimeInterface $start_date = null;

    public function getStart_date(): ?\DateTimeInterface
    {
        return $this->start_date;
    }

    public function getStartDate(): ?\DateTimeInterface
    {
        return $this->getStart_date();
    }

    public function setStart_date(?\DateTimeInterface $start_date): self
    {
        $this->start_date = $start_date;
        return $this;
    }

    public function setStartDate(?\DateTimeInterface $start_date): self
    {
        return $this->setStart_date($start_date);
    }

    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTimeInterface $end_date = null;

    public function getEnd_date(): ?\DateTimeInterface
    {
        return $this->end_date;
    }

    public function getEndDate(): ?\DateTimeInterface
    {
        return $this->getEnd_date();
    }

    public function setEnd_date(?\DateTimeInterface $end_date): self
    {
        $this->end_date = $end_date;
        return $this;
    }

    public function setEndDate(?\DateTimeInterface $end_date): self
    {
        return $this->setEnd_date($end_date);
    }

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', referencedColumnName: 'id', nullable: false)]
    private ?User $createdBy = null;

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    /** @internal Blameable field — set once at creation */
    /** @internal */ public function initCreatedBy(?User $createdBy): self
    {
        if ($this->createdBy === null) {
            $this->createdBy = $createdBy;
        }
        return $this;
    }
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $quiz_timer_seconds = null;

    public function getQuiz_timer_seconds(): ?int
    {
        return $this->quiz_timer_seconds;
    }

    public function getQuizTimerSeconds(): ?int
    {
        return $this->getQuiz_timer_seconds();
    }

    public function setQuiz_timer_seconds(?int $quiz_timer_seconds): self
    {
        $this->quiz_timer_seconds = $quiz_timer_seconds;
        return $this;
    }

    public function setQuizTimerSeconds(?int $quiz_timer_seconds): self
    {
        return $this->setQuiz_timer_seconds($quiz_timer_seconds);
    }

}
