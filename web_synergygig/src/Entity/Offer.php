<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

use App\Repository\OfferRepository;
use App\Entity\Trait\TimestampTrait;
use App\Entity\Trait\BlameableTrait;
use App\Entity\Embeddable\Money;

#[ORM\Entity(repositoryClass: OfferRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'offer')]
class Offer
{
    use TimestampTrait;
    use BlameableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /** @var Collection<int, Contract> */
    #[ORM\OneToMany(mappedBy: 'offer', targetEntity: Contract::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $contracts;

    /** @var Collection<int, JobApplication> */
    #[ORM\OneToMany(mappedBy: 'offer', targetEntity: JobApplication::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $jobApplications;

    /** @var Collection<int, Interview> */
    #[ORM\OneToMany(mappedBy: 'offer', targetEntity: Interview::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $interviews;

    public function __construct()
    {
        $this->contracts = new ArrayCollection();
        $this->jobApplications = new ArrayCollection();
        $this->interviews = new ArrayCollection();
        $this->budget = new Money();
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

    /** @return Collection<int, Contract> */
    public function getContracts(): Collection { return $this->contracts; }
    /** @return Collection<int, JobApplication> */
    public function getJobApplications(): Collection { return $this->jobApplications; }
    /** @return Collection<int, Interview> */
    public function getInterviews(): Collection { return $this->interviews; }

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

    #[ORM\Column(type: 'string', nullable: false)]
    private ?string $offer_type = null;

    public function getOffer_type(): ?string
    {
        return $this->offer_type;
    }

    public function getOfferType(): ?string
    {
        return $this->getOffer_type();
    }

    public function setOffer_type(string $offer_type): self
    {
        $this->offer_type = $offer_type;
        return $this;
    }

    public function setOfferType(string $offer_type): self
    {
        return $this->setOffer_type($offer_type);
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

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $required_skills = null;

    public function getRequired_skills(): ?string
    {
        return $this->required_skills;
    }

    public function getRequiredSkills(): ?string
    {
        return $this->getRequired_skills();
    }

    public function setRequired_skills(?string $required_skills): self
    {
        $this->required_skills = $required_skills;
        return $this;
    }

    public function setRequiredSkills(?string $required_skills): self
    {
        return $this->setRequired_skills($required_skills);
    }

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $location = null;

    public function getLocation(): ?string
    {
        return $this->location;
    }

    public function setLocation(?string $location): self
    {
        $this->location = $location;
        return $this;
    }

    #[ORM\Embedded(class: Money::class, columnPrefix: 'money_')]
    private Money $budget;

    public function getBudget(): Money
    {
        return $this->budget;
    }

    public function setBudget(Money $budget): self
    {
        $this->budget = $budget;
        return $this;
    }

    public function getAmount(): ?string
    {
        return $this->budget->getAmount() !== 0 ? (string) ($this->budget->getAmount() / 100) : null;
    }

    public function setAmount(?string $amount): self
    {
        $cents = $amount !== null ? (int) round((float) $amount * 100) : 0;
        $this->budget = new Money($cents, $this->budget->getCurrency());
        return $this;
    }

    public function getCurrency(): ?string
    {
        return $this->budget->getCurrency();
    }

    public function setCurrency(?string $currency): self
    {
        $this->budget = new Money($this->budget->getAmount(), $currency ?? 'USD');
        return $this;
    }

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'owner_id', referencedColumnName: 'id')]
    private ?User $owner = null;

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function setOwner(?User $owner): self
    {
        $this->owner = $owner;
        return $this;
    }

    #[ORM\ManyToOne(targetEntity: Department::class)]
    #[ORM\JoinColumn(name: 'department_id', referencedColumnName: 'id')]
    private ?Department $department = null;

    public function getDepartment(): ?Department
    {
        return $this->department;
    }

    public function setDepartment(?Department $department): self
    {
        $this->department = $department;
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
}
