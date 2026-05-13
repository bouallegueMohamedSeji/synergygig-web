<?php

namespace App\Service;

use App\Entity\Leave;

class LeaveManager
{
    public function validate(Leave $leave): bool
    {
        if (empty($leave->getType())) {
            throw new \InvalidArgumentException('Le type de congé est obligatoire');
        }

        if ($leave->getStartDate() === null || $leave->getEndDate() === null) {
            throw new \InvalidArgumentException('Les dates de début et de fin sont obligatoires');
        }

        if ($leave->getEndDate() <= $leave->getStartDate()) {
            throw new \InvalidArgumentException('La date de fin doit être postérieure à la date de début');
        }

        return true;
    }
}
