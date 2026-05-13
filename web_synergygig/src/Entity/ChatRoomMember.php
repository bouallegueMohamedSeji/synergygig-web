<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

use App\Repository\ChatRoomMemberRepository;

#[ORM\Entity(repositoryClass: ChatRoomMemberRepository::class)]
#[ORM\Table(name: 'chat_room_members')]
class ChatRoomMember
{
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

    #[ORM\ManyToOne(targetEntity: ChatRoom::class, inversedBy: 'members')]
    #[ORM\JoinColumn(name: 'room_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?ChatRoom $room = null;

    public function getRoom(): ?ChatRoom
    {
        return $this->room;
    }

    public function setRoom(?ChatRoom $room): self
    {
        $this->room = $room;
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

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $role = null;

    public function getRole(): ?string
    {
        return $this->role;
    }

    public function setRole(?string $role): self
    {
        $this->role = $role;
        return $this;
    }

    #[ORM\Column(type: 'datetime', nullable: false)]
    private ?\DateTimeInterface $joined_at = null;

    public function getJoined_at(): ?\DateTimeInterface
    {
        return $this->joined_at;
    }

    public function getJoinedAt(): ?\DateTimeInterface
    {
        return $this->getJoined_at();
    }

    /** @internal Timestamp — set once at creation */
    public function initJoined_at(\DateTimeInterface $joined_at): self
    {
        $this->joined_at = $joined_at;
        return $this;
    }

    public function initJoinedAt(\DateTimeInterface $joined_at): self
    {
        return $this->initJoined_at($joined_at);
    }

}
