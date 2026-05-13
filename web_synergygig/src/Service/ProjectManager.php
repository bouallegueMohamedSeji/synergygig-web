<?php

namespace App\Service;

use App\Entity\Project;

class ProjectManager
{
    public function validate(Project $project): bool
    {
        if (empty(trim($project->getName() ?? ''))) {
            throw new \InvalidArgumentException('Le nom du projet est obligatoire');
        }

        if ($project->getStartDate() !== null && $project->getDeadline() !== null) {
            if ($project->getDeadline() <= $project->getStartDate()) {
                throw new \InvalidArgumentException('La date limite doit être postérieure à la date de début');
            }
        }

        return true;
    }
}
