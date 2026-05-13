<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

use App\Repository\CommunityGroupRepository;
use App\Entity\Trait\TimestampTrait;

#[ORM\Entity(repositoryClass: CommunityGroupRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'community_group')]
class CommunityGroup
{
    use TimestampTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /** @var Collection<int, Post> */
    #[ORM\OneToMany(mappedBy: 'group', targetEntity: Post::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $posts;

    /** @var Collection<int, GroupMember> */
    #[ORM\OneToMany(mappedBy: 'group', targetEntity: GroupMember::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $groupMembers;

    public function __construct()
    {
        $this->posts = new ArrayCollection();
        $this->groupMembers = new ArrayCollection();
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

    /** @return Collection<int, Post> */
    public function getPosts(): Collection { return $this->posts; }
    /** @return Collection<int, GroupMember> */
    public function getGroupMembers(): Collection { return $this->groupMembers; }

    #[ORM\Column(type: 'string', nullable: false)]
    private ?string $name = null;

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
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

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $image_base64 = null;

    public function getImage_base64(): ?string
    {
        return $this->image_base64;
    }

    public function setImage_base64(?string $image_base64): self
    {
        $this->image_base64 = $image_base64;
        return $this;
    }

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'creator_id', referencedColumnName: 'id', nullable: false)]
    private ?User $creator = null;

    public function getCreator(): ?User
    {
        return $this->creator;
    }

    /** @internal Blameable field — set once at creation */
    /** @internal */ public function initCreator(?User $creator): self
    {
        if ($this->creator === null) {
            $this->creator = $creator;
        }
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $privacy = null;

    public function getPrivacy(): ?string
    {
        return $this->privacy;
    }

    public function setPrivacy(?string $privacy): self
    {
        $this->privacy = $privacy;
        return $this;
    }

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $member_count = null;

    public function getMember_count(): ?int
    {
        return $this->member_count;
    }

    public function getMemberCount(): ?int
    {
        return $this->getMember_count();
    }

    public function setMember_count(?int $member_count): self
    {
        $this->member_count = $member_count;
        return $this;
    }

    public function setMemberCount(?int $member_count): self
    {
        return $this->setMember_count($member_count);
    }
}
