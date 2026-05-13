<?php

namespace App\Service;

use App\Entity\Post;
use App\Entity\CommunityGroup;

class CommunityManager
{
    private const MIN_POST_LENGTH = 3;
    private const MAX_GROUP_NAME_LENGTH = 100;

    public function validatePost(Post $post): bool
    {
        if (empty(trim($post->getContent() ?? ''))) {
            throw new \InvalidArgumentException('Le contenu du post est obligatoire');
        }

        if (mb_strlen(trim((string) $post->getContent())) < self::MIN_POST_LENGTH) {
            throw new \InvalidArgumentException('Le contenu du post doit contenir au moins 3 caractères');
        }

        return true;
    }

    public function validateGroup(CommunityGroup $group): bool
    {
        if (empty(trim($group->getName() ?? ''))) {
            throw new \InvalidArgumentException('Le nom du groupe est obligatoire');
        }

        if (mb_strlen(trim((string) $group->getName())) > self::MAX_GROUP_NAME_LENGTH) {
            throw new \InvalidArgumentException('Le nom du groupe ne peut pas dépasser 100 caractères');
        }

        return true;
    }
}
