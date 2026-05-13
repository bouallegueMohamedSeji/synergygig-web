<?php

namespace App\EventListener;

use App\Entity\Trait\BlameableTrait;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\SecurityBundle\Security;

#[AsDoctrineListener(event: Events::prePersist)]
class BlameableListener
{
    public function __construct(private Security $security)
    {
    }

    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $args->getObject();

        if (!$this->usesTrait($entity, BlameableTrait::class)) {
            return;
        }

        if (!method_exists($entity, 'getCreatedBy') || !method_exists($entity, 'initCreatedBy')) {
            return;
        }

        if ($entity->getCreatedBy() !== null) {
            return;
        }

        $user = $this->security->getUser();
        if ($user !== null) {
            $entity->initCreatedBy($user);
        }
    }

    private function usesTrait(object $entity, string $traitClass): bool
    {
        $class = get_class($entity);
        do {
            if (in_array($traitClass, class_uses($class) ?: [], true)) {
                return true;
            }
        } while ($class = get_parent_class($class));

        return false;
    }
}
