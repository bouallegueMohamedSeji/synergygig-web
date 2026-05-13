<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

use App\Repository\LeaveRepository;
use App\Entity\Trait\TimestampTrait;
use App\Entity\Trait\BlameableTrait;

#[ORM\Entity(repositoryClass: LeaveRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'leaves')]
class Leave
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
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id')]
    private ?User $user = null;

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: false)]
    private ?string $type = null;

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;
        return $this;
    }

    #[ORM\Column(type: 'date', nullable: false)]
    private ?\DateTimeInterface $start_date = null;

    public function getStart_date(): ?\DateTimeInterface
    {
        return $this->start_date;
    }

    public function getStartDate(): ?\DateTimeInterface
    {
        return $this->getStart_date();
    }

    public function setStart_date(\DateTimeInterface $start_date): self
    {
        $this->start_date = $start_date;
        return $this;
    }

    public function setStartDate(\DateTimeInterface $start_date): self
    {
        return $this->setStart_date($start_date);
    }

    #[ORM\Column(type: 'date', nullable: false)]
    private ?\DateTimeInterface $end_date = null;

    public function getEnd_date(): ?\DateTimeInterface
    {
        return $this->end_date;
    }

    public function getEndDate(): ?\DateTimeInterface
    {
        return $this->getEnd_date();
    }

    public function setEnd_date(\DateTimeInterface $end_date): self
    {
        $this->end_date = $end_date;
        return $this;
    }

    public function setEndDate(\DateTimeInterface $end_date): self
    {
        return $this->setEnd_date($end_date);
    }

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $reason = null;

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function setReason(?string $reason): self
    {
        $this->reason = $reason;
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

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $rejection_reason = null;

    public function getRejection_reason(): ?string
    {
        return $this->rejection_reason;
    }

    public function getRejectionReason(): ?string
    {
        return $this->getRejection_reason();
    }

    public function setRejection_reason(?string $rejection_reason): self
    {
        $this->rejection_reason = $rejection_reason;
        return $this;
    }

    public function setRejectionReason(?string $rejection_reason): self
    {
        return $this->setRejection_reason($rejection_reason);
    }

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $attachment = null;

    public function getAttachment(): ?string
    {
        return $this->attachment;
    }

    public function setAttachment(?string $attachment): self
    {
        $this->attachment = $attachment;
        return $this;
    }
}
