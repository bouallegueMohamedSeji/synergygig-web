<?php

namespace App\Controller;

use App\Entity\Offer;
use App\Entity\User;
use App\Form\OfferType;
use App\Repository\OfferRepository;
use App\Repository\JobApplicationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Project Owner — manage own offers (CRUD, DRAFT-only edit/delete).
 */
#[Route('/project-owner')]
#[IsGranted('ROLE_PROJECT_OWNER')]
class ProjectOwnerController extends AbstractController
{
    #[Route('/offers', name: 'app_project_owner_offers')]
    public function myOffers(Request $request, OfferRepository $repo): Response
    {
        $user = $this->getUser();

        $qb = $repo->createQueryBuilder('o')
            ->where('o.owner = :user')
            ->setParameter('user', $user)
            ->orderBy('o.id', 'DESC');

        $status = $request->query->get('status');
        if ($status) {
            $qb->andWhere('o.status = :status')->setParameter('status', $status);
        }

        $q = $request->query->get('q');
        if ($q) {
            $qb->andWhere('LOWER(o.title) LIKE :q')
               ->setParameter('q', '%' . mb_strtolower((string) $q) . '%');
        }

        $offers = $qb->getQuery()->getResult();

        return $this->render('project_owner/offers.html.twig', [
            'offers' => $offers,
            'draftCount' => $repo->count(['owner' => $user, 'status' => 'DRAFT']),
            'openCount' => $repo->count(['owner' => $user, 'status' => 'OPEN']),
            'closedCount' => $repo->count(['owner' => $user, 'status' => 'CLOSED']),
            'totalCount' => $repo->count(['owner' => $user]),
            'currentStatus' => $status,
        ]);
    }

    #[Route('/offers/new', name: 'app_project_owner_offer_new')]
    public function newOffer(Request $request, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Invalid authenticated user.');
        }
        $offer = new Offer();
        $offer->setOwner($user);
        $offer->setStatus('DRAFT');

        $form = $this->createForm(OfferType::class, $offer);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($offer);
            $em->flush();
            $this->addFlash('success', 'Offer created as DRAFT. It will be reviewed by HR.');
            return $this->redirectToRoute('app_project_owner_offers');
        }

        return $this->render('project_owner/offer_form.html.twig', [
            'form' => $form->createView(),
            'is_edit' => false,
        ]);
    }

    /**
     * Edit — only allowed when offer is still DRAFT.
     */
    #[Route('/offers/{id}/edit', name: 'app_project_owner_offer_edit', requirements: ['id' => '\d+'])]
    public function editOffer(Offer $offer, Request $request, EntityManagerInterface $em): Response
    {
        if ($offer->getOwner() !== $this->getUser()) {
            $this->addFlash('error', 'You can only edit your own offers.');
            return $this->redirectToRoute('app_project_owner_offers');
        }

        if ($offer->getStatus() !== 'DRAFT') {
            $this->addFlash('error', 'You can only edit offers that are still in DRAFT status.');
            return $this->redirectToRoute('app_project_owner_offers');
        }

        $form = $this->createForm(OfferType::class, $offer);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Offer updated.');
            return $this->redirectToRoute('app_project_owner_offers');
        }

        return $this->render('project_owner/offer_form.html.twig', [
            'form' => $form->createView(),
            'is_edit' => true,
            'offer' => $offer,
        ]);
    }

    /**
     * Delete — only allowed when offer is still DRAFT.
     */
    #[Route('/offers/{id}/delete', name: 'app_project_owner_offer_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deleteOffer(Offer $offer, Request $request, EntityManagerInterface $em): Response
    {
        if ($offer->getOwner() !== $this->getUser()) {
            $this->addFlash('error', 'You can only delete your own offers.');
            return $this->redirectToRoute('app_project_owner_offers');
        }

        if ($offer->getStatus() !== 'DRAFT') {
            $this->addFlash('error', 'You can only delete offers that are still in DRAFT status.');
            return $this->redirectToRoute('app_project_owner_offers');
        }

        if ($this->isCsrfTokenValid('delete' . $offer->getId(), (string) $request->request->get('_token'))) {
            $em->remove($offer);
            $em->flush();
            $this->addFlash('success', 'Offer deleted.');
        }

        return $this->redirectToRoute('app_project_owner_offers');
    }

    /**
     * View applications for one of my offers (read-only — HR manages acceptance).
     */
    #[Route('/offers/{id}/applications', name: 'app_project_owner_applications', requirements: ['id' => '\d+'])]
    public function offerApplications(Offer $offer, JobApplicationRepository $appRepo): Response
    {
        if ($offer->getOwner() !== $this->getUser()) {
            $this->addFlash('error', 'Access denied.');
            return $this->redirectToRoute('app_project_owner_offers');
        }

        $applications = $appRepo->findBy(['offer' => $offer], ['applied_at' => 'DESC']);

        return $this->render('project_owner/applications.html.twig', [
            'offer' => $offer,
            'applications' => $applications,
        ]);
    }
}
