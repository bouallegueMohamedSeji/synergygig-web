<?php

namespace App\Controller;

use App\Entity\JobApplication;
use App\Entity\Offer;
use App\Form\OfferType;
use App\Repository\JobApplicationRepository;
use App\Repository\OfferRepository;
use App\Repository\InterviewRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class JobApplicationController extends AbstractController
{
    // ─── HR: Offer management (publish / decline) ───────────────────

    /**
     * HR Dashboard — pending drafts + published offers with app counts.
     */
    #[Route('/hr/offers', name: 'app_hr_offers')]
    #[IsGranted('ROLE_HR')]
    public function hrOffers(Request $request, OfferRepository $repo, JobApplicationRepository $appRepo): Response
    {
        $tab = trim((string) $request->query->get('tab', 'pending'));

        if ($tab === 'published') {
            $offers = $repo->createQueryBuilder('o')
                ->where('o.status IN (:s)')
                ->setParameter('s', ['OPEN', 'CLOSED'])
                ->orderBy('o.id', 'DESC')
                ->getQuery()->getResult();
        } else {
            $offers = $repo->findBy(['status' => 'DRAFT'], ['id' => 'DESC']);
        }

        // Count applications per offer + collect applicant names
        $appCounts = [];
        $applicants = [];
        foreach ($offers as $offer) {
            $apps = $appRepo->findBy(['offer' => $offer]);
            $appCounts[$offer->getId()] = count($apps);
            $names = [];
            foreach ($apps as $app) {
                if ($app->getApplicant()) {
                    $names[] = $app->getApplicant()->getFirstName() . ' ' . $app->getApplicant()->getLastName();
                }
            }
            $applicants[$offer->getId()] = $names;
        }

        $pendingCount = $repo->count(['status' => 'DRAFT']);
        $publishedCount = (int) $repo->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->where('o.status IN (:s)')
            ->setParameter('s', ['OPEN', 'CLOSED'])
            ->getQuery()->getSingleScalarResult();

        return $this->render('hr/offers.html.twig', [
            'offers' => $offers,
            'tab' => $tab,
            'pendingCount' => $pendingCount,
            'publishedCount' => $publishedCount,
            'appCounts' => $appCounts,
            'applicants' => $applicants,
        ]);
    }

    /**
     * HR publishes a DRAFT offer → status becomes OPEN (visible in marketplace).
     */
    #[Route('/hr/offers/{id}/publish', name: 'app_hr_offer_publish', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_HR')]
    public function publishOffer(Offer $offer, Request $request, EntityManagerInterface $em): Response
    {
        if ($offer->getStatus() !== 'DRAFT') {
            $this->addFlash('error', 'Only DRAFT offers can be published.');
            return $this->redirectToRoute('app_hr_offers');
        }

        if ($this->isCsrfTokenValid('publish' . $offer->getId(), (string) $request->request->get('_token'))) {
            $offer->setStatus('OPEN');
            $em->flush();
            $this->addFlash('success', 'Offer "' . $offer->getTitle() . '" published to marketplace.');
        }

        return $this->redirectToRoute('app_hr_offers');
    }

    /**
     * HR declines a DRAFT offer → status becomes CANCELLED.
     */
    #[Route('/hr/offers/{id}/decline', name: 'app_hr_offer_decline', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_HR')]
    public function declineOffer(Offer $offer, Request $request, EntityManagerInterface $em): Response
    {
        if ($offer->getStatus() !== 'DRAFT') {
            $this->addFlash('error', 'Only DRAFT offers can be declined.');
            return $this->redirectToRoute('app_hr_offers');
        }

        if ($this->isCsrfTokenValid('decline' . $offer->getId(), (string) $request->request->get('_token'))) {
            $offer->setStatus('CANCELLED');
            $em->flush();
            $this->addFlash('success', 'Offer "' . $offer->getTitle() . '" declined.');
        }

        return $this->redirectToRoute('app_hr_offers');
    }

    /**
     * HR edits an offer.
     */
    #[Route('/hr/offers/{id}/edit', name: 'app_hr_offer_edit', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_HR')]
    public function editOffer(Offer $offer, Request $request, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(OfferType::class, $offer);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Offer "' . $offer->getTitle() . '" updated.');
            return $this->redirectToRoute('app_hr_offers');
        }

        return $this->render('hr/offer_form.html.twig', [
            'form' => $form->createView(),
            'offer' => $offer,
        ]);
    }

    /**
     * HR deletes an offer.
     */
    #[Route('/hr/offers/{id}/delete', name: 'app_hr_offer_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_HR')]
    public function deleteOffer(Offer $offer, Request $request, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete' . $offer->getId(), (string) $request->request->get('_token'))) {
            $title = $offer->getTitle();
            $em->remove($offer);
            $em->flush();
            $this->addFlash('success', 'Offer "' . $title . '" deleted.');
        }

        return $this->redirectToRoute('app_hr_offers');
    }

    // ─── HR: Application review ─────────────────────────────────────

    /**
     * HR views all applications (or filtered by offer).
     */
    #[Route('/applications', name: 'app_application_index')]
    #[IsGranted('ROLE_HR')]
    public function index(Request $request, JobApplicationRepository $repo): Response
    {
        $qb = $repo->createQueryBuilder('a')
            ->leftJoin('a.offer', 'o')->addSelect('o')
            ->leftJoin('a.applicant', 'u')->addSelect('u')
            ->orderBy('a.id', 'DESC');

        $status = $request->query->get('status');
        if ($status) {
            $qb->andWhere('a.status = :status')->setParameter('status', $status);
        }

        $offerId = $request->query->get('offer');
        if ($offerId) {
            $qb->andWhere('o.id = :oid')->setParameter('oid', $offerId);
        }

        $q = trim((string) $request->query->get('q', ''));
        if ($q !== '') {
            $qb->andWhere('LOWER(o.title) LIKE :q OR LOWER(u.first_name) LIKE :q OR LOWER(u.last_name) LIKE :q')
               ->setParameter('q', '%' . mb_strtolower($q) . '%');
        }

        $applications = $qb->getQuery()->getResult();

        return $this->render('application/index.html.twig', [
            'applications' => $applications,
        ]);
    }

    /**
     * HR views a single application.
     */
    #[Route('/applications/{id}', name: 'app_application_show', requirements: ['id' => '\d+'])]
    public function show(JobApplication $application): Response
    {
        if (!$this->isGranted('ROLE_HR') && $application->getApplicant() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('application/show.html.twig', [
            'application' => $application,
        ]);
    }

    /**
     * HR accepts application → status ACCEPTED, redirect to interview form.
     */
    #[Route('/applications/{id}/accept', name: 'app_application_accept', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_HR')]
    public function accept(JobApplication $application, Request $request, EntityManagerInterface $em): Response
    {
        if ($application->getStatus() !== 'PENDING' && $application->getStatus() !== 'REVIEWED') {
            $this->addFlash('error', 'This application has already been processed.');
            return $this->redirectToRoute('app_application_index');
        }

        if ($this->isCsrfTokenValid('accept' . $application->getId(), (string) $request->request->get('_token'))) {
            $application->setStatus('ACCEPTED');
            $application->initReviewedAt(new \DateTime());
            $em->flush();

            $this->addFlash('success', 'Application accepted. Please schedule the interview.');

            // Redirect to interview form with candidate and offer pre-filled
            return $this->redirectToRoute('app_interview_new', [
                'candidate' => $application->getApplicant() ? $application->getApplicant()->getId() : null,
                'offer' => $application->getOffer() ? $application->getOffer()->getId() : null,
            ]);
        }

        return $this->redirectToRoute('app_application_index');
    }

    /**
     * HR rejects application.
     */
    #[Route('/applications/{id}/reject', name: 'app_application_reject', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_HR')]
    public function reject(JobApplication $application, Request $request, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('reject' . $application->getId(), (string) $request->request->get('_token'))) {
            $application->setStatus('REJECTED');
            $application->initReviewedAt(new \DateTime());
            $em->flush();
            $this->addFlash('success', 'Application rejected.');
        }

        return $this->redirectToRoute('app_application_index');
    }

    // ─── Gig Worker: My Applications ────────────────────────────────

    #[Route('/applications/my', name: 'app_my_applications')]
    public function myApplications(Request $request, JobApplicationRepository $repo, InterviewRepository $interviewRepo): Response
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('app_offer_index');
        }

        $qb = $repo->createQueryBuilder('a')
            ->leftJoin('a.offer', 'o')->addSelect('o')
            ->where('a.applicant = :user')
            ->setParameter('user', $user)
            ->orderBy('a.applied_at', 'DESC');

        $status = $request->query->get('status');
        if ($status) {
            $qb->andWhere('a.status = :status')->setParameter('status', $status);
        }

        $applications = $qb->getQuery()->getResult();

        // Fetch interviews for accepted applications
        $interviews = [];
        foreach ($applications as $app) {
            if ($app->getStatus() === 'ACCEPTED') {
                $interview = $interviewRepo->findOneBy(['application' => $app]);
                if ($interview) {
                    $interviews[$app->getId()] = $interview;
                }
            }
        }

        return $this->render('application/my_applications.html.twig', [
            'applications' => $applications,
            'interviews' => $interviews,
            'currentStatus' => $status,
            'pendingCount' => $repo->count(['applicant' => $user, 'status' => 'PENDING']),
            'acceptedCount' => $repo->count(['applicant' => $user, 'status' => 'ACCEPTED']),
            'rejectedCount' => $repo->count(['applicant' => $user, 'status' => 'REJECTED']),
            'totalCount' => $repo->count(['applicant' => $user]),
        ]);
    }
}