<?php

namespace App\Service;

use App\Entity\User;

class UserManager
{
    public function validate(User $user): bool
    {
        if (empty(trim($user->getFirstName() ?? ''))) {
            throw new \InvalidArgumentException('Le prénom est obligatoire');
        }

        if (empty(trim($user->getLastName() ?? ''))) {
            throw new \InvalidArgumentException('Le nom est obligatoire');
        }

        if (empty($user->getEmail()) || !filter_var($user->getEmail(), FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('L\'email est invalide');
        }

        if (empty($user->getPassword()) || strlen($user->getPassword()) < 8) {
            throw new \InvalidArgumentException('Le mot de passe doit contenir au moins 8 caractères');
        }

        return true;
    }
}
