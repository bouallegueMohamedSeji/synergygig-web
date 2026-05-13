<?php

namespace App\Service;

use App\Entity\Interview;

class InterviewManager
{
    private const VALID_STATUSES = ['scheduled', 'completed', 'cancelled'];

    public function validate(Interview $interview): bool
    {
        if ($interview->getDateTime() === null) {
            throw new \InvalidArgumentException('La date et l\'heure de l\'entretien sont obligatoires');
        }

        $now = new \DateTime();
        if ($interview->getDateTime() < $now) {
            throw new \InvalidArgumentException('La date de l\'entretien ne peut pas être dans le passé');
        }

        if ($interview->getStatus() !== null && !in_array($interview->getStatus(), self::VALID_STATUSES, true)) {
            throw new \InvalidArgumentException('Le statut doit être : scheduled, completed ou cancelled');
        }

        return true;
    }
}
