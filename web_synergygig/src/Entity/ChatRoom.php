<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

use App\Repository\ChatRoomRepository;
use App\Entity\Trait\TimestampTrait;

#[ORM\Entity(repositoryClass: ChatRoomRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'chat_rooms')]
class ChatRoom
{
    use TimestampTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /** @var Collection<int, Message> */
    #[ORM\OneToMany(mappedBy: 'room', targetEntity: Message::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $messages;

    /** @var Collection<int, ChatRoomMember> */
    #[ORM\OneToMany(mappedBy: 'room', targetEntity: ChatRoomMember::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $members;

    public function __construct(?User $createdBy = null)
    {
        $this->messages = new ArrayCollection();
        $this->members = new ArrayCollection();
        if ($createdBy !== null) {
            $this->createdBy = $createdBy;
        }
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

    /** @return Collection<int, Message> */
    public function getMessages(): Collection { return $this->messages; }

    /** @return Collection<int, ChatRoomMember> */
    public function getMembers(): Collection { return $this->members; }

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $name = null;

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): self
    {
        $this->name = $name;
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $type = null;

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): self
    {
        $this->type = $type;
        return $this;
    }

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', referencedColumnName: 'id', nullable: false)]
    private ?User $createdBy = null;

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    /** @internal Use constructor to set blameable field */
    /** @internal */ public function initCreatedBy(?User $createdBy): self
    {
        if ($this->createdBy === null) {
            $this->createdBy = $createdBy;
        }
        return $this;
    }
}
