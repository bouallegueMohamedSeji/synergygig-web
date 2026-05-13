<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

use App\Repository\NotificationRepository;
use App\Entity\Trait\TimestampTrait;
use App\Entity\Trait\BlameableTrait;

#[ORM\Entity(repositoryClass: NotificationRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'notifications')]
class Notification
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

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $title = null;

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): self
    {
        $this->title = $title;
        return $this;
    }

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $body = null;

    public function getBody(): ?string
    {
        return $this->body;
    }

    public function setBody(?string $body): self
    {
        $this->body = $body;
        return $this;
    }

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $reference_id = null;

    public function getReference_id(): ?int
    {
        return $this->reference_id;
    }

    public function getReferenceId(): ?int
    {
        return $this->getReference_id();
    }

    public function setReference_id(?int $reference_id): self
    {
        $this->reference_id = $reference_id;
        return $this;
    }

    public function setReferenceId(?int $reference_id): self
    {
        return $this->setReference_id($reference_id);
    }

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $reference_type = null;

    public function getReference_type(): ?string
    {
        return $this->reference_type;
    }

    public function getReferenceType(): ?string
    {
        return $this->getReference_type();
    }

    public function setReference_type(?string $reference_type): self
    {
        $this->reference_type = $reference_type;
        return $this;
    }

    public function setReferenceType(?string $reference_type): self
    {
        return $this->setReference_type($reference_type);
    }

    #[ORM\Column(type: 'boolean', nullable: true)]
    private ?bool $is_read = null;

    public function is_read(): ?bool
    {
        return $this->is_read;
    }

    public function isRead(): ?bool
    {
        return $this->is_read();
    }

    public function setIs_read(?bool $is_read): self
    {
        $this->is_read = $is_read;
        return $this;
    }

    public function setIsRead(?bool $is_read): self
    {
        return $this->setIs_read($is_read);
    }
}
