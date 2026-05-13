<?php

namespace App\Controller;

use App\Entity\Contract;
use App\Entity\Interview;
use App\Entity\Offer;
use App\Entity\User;
use App\Form\InterviewType;
use App\Repository\ContractRepository;
use App\Repository\InterviewRepository;
use App\Service\N8nWebhookService;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/interviews')]
#[IsGranted('ROLE_USER')]
class InterviewController extends AbstractController
{
    #[Route('/', name: 'app_interview_index')]
    public function index(Request $request, InterviewRepository $repo): Response
    {
        $qb = $repo->createQueryBuilder('i')
            ->leftJoin('i.candidate', 'c')->addSelect('c')
            ->leftJoin('i.organizer', 'org')->addSelect('org')
            ->leftJoin('i.offer', 'o')->addSelect('o')
            ->orderBy('i.date_time', 'DESC');

        // Gig workers only see interviews where they are the candidate
        if (!$this->isGranted('ROLE_HR')) {
            $qb->andWhere('i.candidate = :user')
               ->setParameter('user', $this->getUser());
        }

        $status = trim((string) $request->query->get('status', ''));
        if ($status !== '') {
            $qb->andWhere('i.status = :status')->setParameter('status', $status);
        }

        $q = trim((string) $request->query->get('q', ''));
        if ($q !== '') {
            $qb->andWhere('LOWER(c.first_name) LIKE :q OR LOWER(c.last_name) LIKE :q OR LOWER(o.title) LIKE :q')
               ->setParameter('q', '%' . mb_strtolower($q) . '%');
        }

        $interviews = $qb->getQuery()->getResult();

        return $this->render('interview/index.html.twig', [
            'interviews' => $interviews,
        ]);
    }

    #[Route('/new', name: 'app_interview_new')]
    #[IsGranted('ROLE_HR')]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $interview = new Interview();

        // Pre-fill organizer with current HR user
        $interview->setOrganizer($this->getUser() instanceof User ? $this->getUser() : null);

        // Pre-fill candidate from query param (e.g. after accepting an application)
        $candidateId = $request->query->get('candidate');
        if ($candidateId) {
            $candidate = $em->getRepository(User::class)->find($candidateId);
            if ($candidate) {
                $interview->setCandidate($candidate);
            }
        }

        // Pre-fill offer from query param
        $offerId = $request->query->get('offer');
        if ($offerId) {
            $offer = $em->getRepository(Offer::class)->find($offerId);
            if ($offer) {
                $interview->setOffer($offer);
            }
        }

        $form = $this->createForm(InterviewType::class, $interview);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($interview);
            $em->flush();
            $this->addFlash('success', 'Interview scheduled.');
            return $this->redirectToRoute('app_interview_index');
        }

        return $this->render('interview/form.html.twig', [
            'form' => $form->createView(),
            'is_edit' => false,
        ]);
    }

    #[Route('/{id}', name: 'app_interview_show', requirements: ['id' => '\d+'])]
    public function show(Interview $interview): Response
    {
        return $this->render('interview/show.html.twig', [
            'interview' => $interview,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_interview_edit', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_HR')]
    public function edit(Request $request, Interview $interview, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(InterviewType::class, $interview);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Interview updated.');
            return $this->redirectToRoute('app_interview_index');
        }

        return $this->render('interview/form.html.twig', [
            'form' => $form->createView(),
            'is_edit' => true,
            'interview' => $interview,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_interview_delete', methods: ['POST'])]
    #[IsGranted('ROLE_HR')]
    public function delete(Request $request, Interview $interview, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete' . $interview->getId(), (string) $request->request->get('_token'))) {
            $em->remove($interview);
            $em->flush();
            $this->addFlash('success', 'Interview deleted.');
        }
        return $this->redirectToRoute('app_interview_index');
    }

    #[Route('/{id}/accept', name: 'app_interview_accept', methods: ['POST'])]
    #[IsGranted('ROLE_HR')]
    public function accept(
        Request $request,
        Interview $interview,
        EntityManagerInterface $em,
        N8nWebhookService $n8n,
        NotificationService $notifier,
        ContractRepository $contractRepo
    ): Response {
        if (!$this->isCsrfTokenValid('accept' . $interview->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_interview_index');
        }

        if ($interview->getStatus() !== 'PENDING') {
            $this->addFlash('error', 'Only pending interviews can be accepted.');
            return $this->redirectToRoute('app_interview_index');
        }

        $interview->setStatus('ACCEPTED');

        // ── Auto-create a DRAFT contract if none exists yet for this applicant+offer ──
        $candidate = $interview->getCandidate();
        $offer     = $interview->getOffer();
        $contract  = null;

        if ($candidate && $offer) {
            $existing = $contractRepo->findOneBy([
                'applicant' => $candidate,
                'offer'     => $offer,
            ]);

            if (!$existing) {
                $contract = new Contract();
                $contract->setApplicant($candidate);
                $contract->setOffer($offer);
                $contract->setOwner($this->getUser() instanceof User ? $this->getUser() : null);
                $contract->setStatus('DRAFT');
                $contract->setCurrency('USD');
                if ($offer->getAmount()) {
                    $contract->setAmount($offer->getAmount());
                }
                $em->persist($contract);
            } else {
                $contract = $existing;
            }
        }

        $em->flush();

        // ── Notify candidate ──
        if ($candidate) {
            $offerTitle = $offer && $offer->getTitle() ? (string) $offer->getTitle() : 'your interview';
            $notifier->interviewAccepted(
                $candidate,
                (int) $interview->getId(),
                $offerTitle,
                (int) ($contract?->getId() ?? 0)
            );
        }

        // ── Fire n8n orchestration webhook (non-blocking, retried) ──
        if ($candidate && $offer && $contract) {
            $acceptedBy = $this->getUser() instanceof User
                ? $this->getUser()->getFirstName() . ' ' . $this->getUser()->getLastName()
                : 'HR';

            $n8n->interviewAccepted(
                (int) $interview->getId(),
                (int) $candidate->getId(),
                $candidate->getFirstName() . ' ' . $candidate->getLastName(),
                $candidate->getEmail() ?? '',
                (int) $offer->getId(),
                (string) $offer->getTitle(),
                (int) $contract->getId(),
                $acceptedBy
            );
        }

        $this->addFlash('success', 'Interview accepted — a draft contract has been created.');
        return $this->redirectToRoute('app_interview_index');
    }

    #[Route('/{id}/decline', name: 'app_interview_decline', methods: ['POST'])]
    #[IsGranted('ROLE_HR')]
    public function decline(Request $request, Interview $interview, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('decline' . $interview->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_interview_index');
        }

        if ($interview->getStatus() !== 'PENDING') {
            $this->addFlash('error', 'Only pending interviews can be declined.');
            return $this->redirectToRoute('app_interview_index');
        }

        $interview->setStatus('REJECTED');
        $em->flush();

        $this->addFlash('success', 'Interview declined.');
        return $this->redirectToRoute('app_interview_index');
    }
}
