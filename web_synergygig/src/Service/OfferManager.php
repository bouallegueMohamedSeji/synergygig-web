<?php

namespace App\Service;

use App\Entity\Offer;
use App\Entity\Contract;

class OfferManager
{
    private const VALID_OFFER_TYPES = ['CDI', 'CDD', 'Freelance', 'Stage'];
    private const VALID_OFFER_STATUSES = ['open', 'closed', 'draft'];
    private const VALID_CONTRACT_STATUSES = ['active', 'expired', 'terminated', 'pending'];

    public function validateOffer(Offer $offer): bool
    {
        if (empty(trim($offer->getTitle() ?? ''))) {
            throw new \InvalidArgumentException('Le titre de l\'offre est obligatoire');
        }

        if (empty(trim($offer->getOfferType() ?? ''))) {
            throw new \InvalidArgumentException('Le type d\'offre est obligatoire');
        }

        if (!in_array($offer->getOfferType(), self::VALID_OFFER_TYPES, true)) {
            throw new \InvalidArgumentException('Type d\'offre invalide : CDI, CDD, Freelance ou Stage');
        }

        if (empty(trim($offer->getStatus() ?? ''))) {
            throw new \InvalidArgumentException('Le statut de l\'offre est obligatoire');
        }

        if (!in_array($offer->getStatus(), self::VALID_OFFER_STATUSES, true)) {
            throw new \InvalidArgumentException('Statut invalide : open, closed ou draft');
        }

        return true;
    }

    public function validateContract(Contract $contract): bool
    {
        if (empty(trim($contract->getStatus() ?? ''))) {
            throw new \InvalidArgumentException('Le statut du contrat est obligatoire');
        }

        if (!in_array($contract->getStatus(), self::VALID_CONTRACT_STATUSES, true)) {
            throw new \InvalidArgumentException('Statut de contrat invalide : active, expired, terminated ou pending');
        }

        if ($contract->getAmount() !== null && (float) $contract->getAmount() < 0) {
            throw new \InvalidArgumentException('Le montant du contrat ne peut pas être négatif');
        }

        return true;
    }
}
