<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

use App\Repository\InterviewRepository;
use App\Entity\Trait\TimestampTrait;
use App\Entity\Trait\BlameableTrait;

#[ORM\Entity(repositoryClass: InterviewRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'interviews')]
class Interview
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

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'organizer_id', referencedColumnName: 'id')]
    private ?User $organizer = null;

    public function getOrganizer(): ?User
    {
        return $this->organizer;
    }

    public function setOrganizer(?User $organizer): self
    {
        $this->organizer = $organizer;
        return $this;
    }

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'candidate_id', referencedColumnName: 'id')]
    private ?User $candidate = null;

    public function getCandidate(): ?User
    {
        return $this->candidate;
    }

    public function setCandidate(?User $candidate): self
    {
        $this->candidate = $candidate;
        return $this;
    }

    #[ORM\Column(type: 'datetime', nullable: false)]
    private ?\DateTimeInterface $date_time = null;

    public function getDate_time(): ?\DateTimeInterface
    {
        return $this->date_time;
    }

    public function getDateTime(): ?\DateTimeInterface
    {
        return $this->getDate_time();
    }

    /** @internal Timestamp — use initDate_time */
    public function initDate_time(\DateTimeInterface $date_time): self
    {
        $this->date_time = $date_time;
        return $this;
    }

    public function initDateTime(?\DateTimeInterface $date_time): self
    {
        if ($date_time !== null) {
            $this->date_time = $date_time;
        }
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
    private ?string $meet_link = null;

    public function getMeet_link(): ?string
    {
        return $this->meet_link;
    }

    public function getMeetLink(): ?string
    {
        return $this->getMeet_link();
    }

    public function setMeet_link(?string $meet_link): self
    {
        $this->meet_link = $meet_link;
        return $this;
    }

    public function setMeetLink(?string $meet_link): self
    {
        return $this->setMeet_link($meet_link);
    }

    #[ORM\ManyToOne(targetEntity: JobApplication::class, inversedBy: 'interviews')]
    #[ORM\JoinColumn(name: 'application_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?JobApplication $application = null;

    public function getApplication(): ?JobApplication
    {
        return $this->application;
    }

    public function setApplication(?JobApplication $application): self
    {
        $this->application = $application;
        return $this;
    }

    #[ORM\ManyToOne(targetEntity: Offer::class, inversedBy: 'interviews')]
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
}
