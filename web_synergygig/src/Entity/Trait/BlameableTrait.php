<?php

namespace App\Entity\Trait;

use App\Entity\User;
use Doctrine\ORM\Mapping as ORM;

trait BlameableTrait
{
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', referencedColumnName: 'id', nullable: false)]
    private ?User $createdBy = null;

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    /** @internal Blameable field — set once at creation */
    public function initCreatedBy(?User $createdBy): self
    {
        if ($this->createdBy === null) {
            $this->createdBy = $createdBy;
        }
        return $this;
    }
}
