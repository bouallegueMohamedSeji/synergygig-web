<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

use App\Repository\UserFollowRepository;
use App\Entity\Trait\TimestampTrait;
use App\Entity\Trait\BlameableTrait;
#[ORM\Entity(repositoryClass: UserFollowRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'user_follows')]
class UserFollow
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
    #[ORM\JoinColumn(name: 'follower_id', referencedColumnName: 'id')]
    private ?User $follower = null;

    public function getFollower(): ?User
    {
        return $this->follower;
    }

    public function setFollower(?User $follower): self
    {
        $this->follower = $follower;
        return $this;
    }

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'followed_id', referencedColumnName: 'id')]
    private ?User $followed = null;

    public function getFollowed(): ?User
    {
        return $this->followed;
    }

    public function setFollowed(?User $followed): self
    {
        $this->followed = $followed;
        return $this;
    }

    /** Alias for setFollowed() — used for semantic clarity in follow/unfollow flows. */
    public function setFollowing(?User $user): self
    {
        return $this->setFollowed($user);
    }

    /** Alias for getFollowed() — used for semantic clarity in follow/unfollow flows. */
    public function getFollowing(): ?User
    {
        return $this->followed;
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
}
