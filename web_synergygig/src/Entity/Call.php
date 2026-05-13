<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

use App\Repository\CallRepository;
use App\Entity\Trait\TimestampTrait;
use App\Entity\Trait\BlameableTrait;

#[ORM\Entity(repositoryClass: CallRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'calls')]
class Call
{
    use TimestampTrait;
    use BlameableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /** @var Collection<int, CallSignal> */
    #[ORM\OneToMany(mappedBy: 'call', targetEntity: CallSignal::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $signals;

    public function __construct()
    {
        $this->signals = new ArrayCollection();
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

    /** @return Collection<int, CallSignal> */
    public function getSignals(): Collection { return $this->signals; }

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'caller_id', referencedColumnName: 'id')]
    private ?User $caller = null;

    public function getCaller(): ?User
    {
        return $this->caller;
    }

    public function setCaller(?User $caller): self
    {
        $this->caller = $caller;
        return $this;
    }

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'callee_id', referencedColumnName: 'id')]
    private ?User $callee = null;

    public function getCallee(): ?User
    {
        return $this->callee;
    }

    public function setCallee(?User $callee): self
    {
        $this->callee = $callee;
        return $this;
    }

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $room_id = null;

    public function getRoom_id(): ?int
    {
        return $this->room_id;
    }

    public function getRoomId(): ?int
    {
        return $this->getRoom_id();
    }

    public function setRoom_id(?int $room_id): self
    {
        $this->room_id = $room_id;
        return $this;
    }

    public function setRoomId(?int $room_id): self
    {
        return $this->setRoom_id($room_id);
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
    private ?string $call_type = null;

    public function getCall_type(): ?string
    {
        return $this->call_type;
    }

    public function getCallType(): ?string
    {
        return $this->getCall_type();
    }

    public function setCall_type(?string $call_type): self
    {
        $this->call_type = $call_type;
        return $this;
    }

    public function setCallType(?string $call_type): self
    {
        return $this->setCall_type($call_type);
    }

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $started_at = null;

    public function getStarted_at(): ?\DateTimeInterface
    {
        return $this->started_at;
    }

    public function getStartedAt(): ?\DateTimeInterface
    {
        return $this->getStarted_at();
    }

    /** @internal Timestamp — set once */
    public function initStarted_at(?\DateTimeInterface $started_at): self
    {
        $this->started_at = $started_at;
        return $this;
    }

    public function initStartedAt(?\DateTimeInterface $started_at): self
    {
        return $this->initStarted_at($started_at);
    }

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $ended_at = null;

    public function getEnded_at(): ?\DateTimeInterface
    {
        return $this->ended_at;
    }

    public function getEndedAt(): ?\DateTimeInterface
    {
        return $this->getEnded_at();
    }

    /** @internal Timestamp — set once */
    public function initEnded_at(?\DateTimeInterface $ended_at): self
    {
        $this->ended_at = $ended_at;
        return $this;
    }

    public function initEndedAt(?\DateTimeInterface $ended_at): self
    {
        return $this->initEnded_at($ended_at);
    }
}
