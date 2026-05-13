<?php

namespace App\Tests\Service;

use App\Entity\Offer;
use App\Entity\Contract;
use App\Service\OfferManager;
use PHPUnit\Framework\TestCase;

class OfferManagerTest extends TestCase
{
    // ─── Offer Tests ──────────────────────────────────────────────

    public function testValidOffer(): void
    {
        $offer = new Offer();
        $offer->setTitle('Développeur Symfony Senior');
        $offer->setOfferType('CDI');
        $offer->setStatus('open');
        $offer->setLocation('Tunis, Tunisie');

        $manager = new OfferManager();
        $this->assertTrue($manager->validateOffer($offer));
    }

    public function testOfferWithoutTitle(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Le titre de l\'offre est obligatoire');

        $offer = new Offer();
        $offer->setOfferType('CDI');
        $offer->setStatus('open');

        $manager = new OfferManager();
        $manager->validateOffer($offer);
    }

    public function testOfferWithInvalidType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Type d\'offre invalide');

        $offer = new Offer();
        $offer->setTitle('Designer UX');
        $offer->setOfferType('Interim');
        $offer->setStatus('open');

        $manager = new OfferManager();
        $manager->validateOffer($offer);
    }

    public function testOfferWithInvalidStatus(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Statut invalide');

        $offer = new Offer();
        $offer->setTitle('Chef de Projet');
        $offer->setOfferType('CDD');
        $offer->setStatus('archived');

        $manager = new OfferManager();
        $manager->validateOffer($offer);
    }

    public function testOfferWithFreelanceType(): void
    {
        $offer = new Offer();
        $offer->setTitle('Consultant Data');
        $offer->setOfferType('Freelance');
        $offer->setStatus('draft');

        $manager = new OfferManager();
        $this->assertTrue($manager->validateOffer($offer));
    }

    // ─── Contract Tests ───────────────────────────────────────────

    public function testValidContract(): void
    {
        $contract = new Contract();
        $contract->setStatus('active');
        $contract->setAmount('5000.00');

        $manager = new OfferManager();
        $this->assertTrue($manager->validateContract($contract));
    }

    public function testContractWithInvalidStatus(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Statut de contrat invalide');

        $contract = new Contract();
        $contract->setStatus('suspended');

        $manager = new OfferManager();
        $manager->validateContract($contract);
    }

    public function testContractWithNegativeAmount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Le montant du contrat ne peut pas être négatif');

        $contract = new Contract();
        $contract->setStatus('active');
        $contract->setAmount('-1000.00');

        $manager = new OfferManager();
        $manager->validateContract($contract);
    }
}
