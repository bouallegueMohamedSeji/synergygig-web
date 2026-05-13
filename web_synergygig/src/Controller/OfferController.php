<?php

namespace App\Controller;

use App\Entity\Offer;
use App\Entity\JobApplication;
use App\Repository\OfferRepository;
use App\Repository\JobApplicationRepository;
use App\Repository\ContractRepository;
use App\Service\ExchangeRateService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Unified Offers & Contracts Hub — all tabs in one page.
 */
#[Route('/offers')]
class OfferController extends AbstractController
{
    #[Route('/', name: 'app_offer_index')]
    public function index(
        Request $request,
        OfferRepository $repo,
        JobApplicationRepository $appRepo,
        ContractRepository $contractRepo,
        ExchangeRateService $exchangeRateService
    ): Response {
        $user = $this->getUser();

        // ── Tab 1: Marketplace (OPEN offers) ──
        $offers = $repo->createQueryBuilder('o')
            ->where('o.status = :status')
            ->setParameter('status', 'OPEN')
            ->orderBy('o.id', 'DESC')
            ->getQuery()->getResult();

        $appliedOfferIds = [];
        if ($user) {
            $apps = $appRepo->findBy(['applicant' => $user]);
            foreach ($apps as $app) {
                /** @var \App\Entity\JobApplication $app */
                if ($app->getOffer()) {
                    $appliedOfferIds[] = $app->getOffer()->getId();
                }
            }
        }

        // ── Tab 2: My Offers (PROJECT_OWNER) ──
        $myOffers = [];
        $myOfferCounts = ['draft' => 0, 'open' => 0, 'closed' => 0, 'total' => 0];
        if ($user && $this->isGranted('ROLE_PROJECT_OWNER')) {
            $myOffers = $repo->createQueryBuilder('o')
                ->where('o.owner = :user')
                ->setParameter('user', $user)
                ->orderBy('o.id', 'DESC')
                ->getQuery()->getResult();
            $myOfferCounts = [
                'draft' => $repo->count(['owner' => $user, 'status' => 'DRAFT']),
                'open' => $repo->count(['owner' => $user, 'status' => 'OPEN']),
                'closed' => $repo->count(['owner' => $user, 'status' => 'CLOSED']),
                'total' => $repo->count(['owner' => $user]),
            ];
        }

        // ── Tab 3: Applications ──
        $myApplications = [];
        $appCounts = ['pending' => 0, 'accepted' => 0, 'rejected' => 0, 'total' => 0];
        if ($user) {
            if ($this->isGranted('ROLE_GIG_WORKER')) {
                $myApplications = $appRepo->createQueryBuilder('a')
                    ->leftJoin('a.offer', 'o')->addSelect('o')
                    ->where('a.applicant = :user')
                    ->setParameter('user', $user)
                    ->orderBy('a.appliedAt', 'DESC')
                    ->getQuery()->getResult();
            } elseif ($this->isGranted('ROLE_HR')) {
                $myApplications = $appRepo->createQueryBuilder('a')
                    ->leftJoin('a.offer', 'o')->addSelect('o')
                    ->leftJoin('a.applicant', 'u')->addSelect('u')
                    ->orderBy('a.appliedAt', 'DESC')
                    ->setMaxResults(50)
                    ->getQuery()->getResult();
            } elseif ($this->isGranted('ROLE_PROJECT_OWNER')) {
                $myApplications = $appRepo->createQueryBuilder('a')
                    ->leftJoin('a.offer', 'o')->addSelect('o')
                    ->leftJoin('a.applicant', 'u')->addSelect('u')
                    ->where('o.owner = :user')
                    ->setParameter('user', $user)
                    ->orderBy('a.appliedAt', 'DESC')
                    ->getQuery()->getResult();
            }
            foreach ($myApplications as $a) {
                $s = $a->getStatus();
                if ($s === 'PENDING') $appCounts['pending']++;
                elseif ($s === 'ACCEPTED') $appCounts['accepted']++;
                elseif ($s === 'REJECTED') $appCounts['rejected']++;
                $appCounts['total']++;
            }
        }

        // ── Tab 4: Contracts ──
        $contracts = [];
        if ($user) {
            $cqb = $contractRepo->createQueryBuilder('c')->orderBy('c.id', 'DESC');
            if (!$this->isGranted('ROLE_HR')) {
                $cqb->andWhere('c.owner = :user OR c.applicant = :user')->setParameter('user', $user);
            }
            $contracts = $cqb->setMaxResults(50)->getQuery()->getResult();
        }

        // ── Currencies for dropdown ──
        $currencies = $exchangeRateService->getCommonCurrencies();

        return $this->render('offer/hub.html.twig', [
            'offers' => $offers,
            'appliedOfferIds' => $appliedOfferIds,
            'myOffers' => $myOffers,
            'myOfferCounts' => $myOfferCounts,
            'myApplications' => $myApplications,
            'appCounts' => $appCounts,
            'contracts' => $contracts,
            'currencies' => $currencies,
        ]);
    }

    #[Route('/{id}', name: 'app_offer_show', requirements: ['id' => '\d+'])]
    public function show(Offer $offer, JobApplicationRepository $appRepo): Response
    {
        // HR should use the HR offers review page, not the marketplace detail
        if ($this->isGranted('ROLE_HR')) {
            return $this->redirectToRoute('app_hr_offers');
        }

        $user = $this->getUser();
        $alreadyApplied = false;
        $existingApplication = null;

        if ($user) {
            $existingApplication = $appRepo->findOneBy([
                'offer' => $offer,
                'applicant' => $user,
            ]);
            $alreadyApplied = $existingApplication !== null;
        }

        return $this->render('offer/show.html.twig', [
            'offer' => $offer,
            'alreadyApplied' => $alreadyApplied,
            'existingApplication' => $existingApplication,
        ]);
    }

    #[Route('/{id}/apply', name: 'app_offer_apply', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function apply(
        Offer $offer,
        Request $request,
        EntityManagerInterface $em,
        JobApplicationRepository $appRepo
    ): Response {
        $user = $this->getUser();
        if (!$user) {
            $this->addFlash('error', 'You must be logged in to apply.');
            return $this->redirectToRoute('app_offer_show', ['id' => $offer->getId()]);
        }

        if ($offer->getOwner() === $user) {
            $this->addFlash('error', 'You cannot apply to your own offer.');
            return $this->redirectToRoute('app_offer_show', ['id' => $offer->getId()]);
        }

        if ($offer->getStatus() !== 'OPEN') {
            $this->addFlash('error', 'This offer is not currently accepting applications.');
            return $this->redirectToRoute('app_offer_show', ['id' => $offer->getId()]);
        }

        $existing = $appRepo->findOneBy([
            'offer' => $offer,
            'applicant' => $user,
        ]);
        if ($existing) {
            $this->addFlash('warning', 'You have already applied to this offer.');
            return $this->redirectToRoute('app_offer_show', ['id' => $offer->getId()]);
        }

        $application = new JobApplication();
        $application->setOffer($offer);
        /** @var \App\Entity\User $user */
        $application->setApplicant($user);
        $application->setStatus('PENDING');
        $application->initAppliedAt(new \DateTime());

        $coverLetter = trim((string) $request->request->get('cover_letter', ''));
        if ($coverLetter !== '') {
            $application->setCoverLetter($coverLetter);
        }

        $em->persist($application);
        $em->flush();

        $this->addFlash('success', 'Application submitted successfully!');
        return $this->redirectToRoute('app_offer_show', ['id' => $offer->getId()]);
    }
}