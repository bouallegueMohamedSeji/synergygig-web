<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

use App\Repository\DepartmentRepository;
use App\Entity\Trait\TimestampTrait;
use App\Entity\Trait\BlameableTrait;

#[ORM\Entity(repositoryClass: DepartmentRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'departments')]
class Department
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

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'manager_id', referencedColumnName: 'id')]
    private ?User $deptManager = null;

    public function getManager(): ?User
    {
        return $this->deptManager;
    }

    public function setManager(?User $manager): self
    {
        $this->deptManager = $manager;
        return $this;
    }

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $allocated_budget = null;

    public function getAllocated_budget(): ?string
    {
        return $this->allocated_budget;
    }

    public function getAllocatedBudget(): ?string
    {
        return $this->allocated_budget;
    }

    public function setAllocated_budget(?string $allocated_budget): self
    {
        $this->allocated_budget = $allocated_budget;
        return $this;
    }

    public function setAllocatedBudget(?string $allocated_budget): self
    {
        $this->allocated_budget = $allocated_budget;
        return $this;
    }
}
