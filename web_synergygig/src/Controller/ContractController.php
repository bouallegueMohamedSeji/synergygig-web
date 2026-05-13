<?php

namespace App\Controller;

use App\Entity\Contract;
use App\Form\ContractType;
use App\Repository\ContractRepository;
use App\Service\AIService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Knp\Component\Pager\PaginatorInterface;
use App\Service\N8nWebhookService;
use App\Service\NotificationService;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[Route('/contracts')]
class ContractController extends AbstractController
{
    #[Route('/', name: 'app_contract_index')]
    public function index(Request $request, ContractRepository $repo, PaginatorInterface $paginator): Response
    {
        $qb = $repo->createQueryBuilder('c')->orderBy('c.id', 'DESC');

        // Non-HR users see only contracts they own or are the applicant on
        if (!$this->isGranted('ROLE_HR')) {
            $user = $this->getUser();
            $qb->andWhere('c.owner = :user OR c.applicant = :user')->setParameter('user', $user);
        }

        $status = $request->query->get('status');
        if ($status) {
            $qb->andWhere('c.status = :status')->setParameter('status', $status);
        }

        $pagination = $paginator->paginate($qb, $request->query->getInt('page', 1), 15);

        return $this->render('contract/index.html.twig', [
            'contracts' => $pagination,
            'pagination' => $pagination,
        ]);
    }

    #[Route('/new', name: 'app_contract_new')]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isGranted('ROLE_HR') && !$this->isGranted('ROLE_PROJECT_OWNER')) {
            throw $this->createAccessDeniedException('Only HR managers or Project Owners can create contracts.');
        }
        $contract = new Contract();
        $form = $this->createForm(ContractType::class, $contract);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $contract->setStatus($contract->getStatus() ?? 'DRAFT');
            $em->persist($contract);
            $em->flush();
            $this->addFlash('success', 'Contract created.');
            return $this->redirectToRoute('app_contract_show', ['id' => $contract->getId()]);
        }

        return $this->render('contract/form.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}', name: 'app_contract_show', requirements: ['id' => '\d+'])]
    public function show(Contract $contract): Response
    {
        return $this->render('contract/show.html.twig', [
            'contract' => $contract,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_contract_edit', requirements: ['id' => '\d+'])]
    public function edit(Contract $contract, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isGranted('ROLE_HR') && !$this->isGranted('ROLE_PROJECT_OWNER')) {
            throw $this->createAccessDeniedException('Only HR managers or Project Owners can edit contracts.');
        }
        if (!$this->isGranted('ROLE_ADMIN') && !$this->isGranted('ROLE_HR') && $contract->getOwner()?->getId() !== $this->getUser()?->getId()) {
            throw $this->createAccessDeniedException('You can only edit your own contracts.');
        }
        $form = $this->createForm(ContractType::class, $contract);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Contract updated.');
            return $this->redirectToRoute('app_contract_show', ['id' => $contract->getId()]);
        }

        return $this->render('contract/form.html.twig', [
            'form' => $form->createView(),
            'contract' => $contract,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_contract_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Contract $contract, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isGranted('ROLE_HR') && !$this->isGranted('ROLE_PROJECT_OWNER')) {
            throw $this->createAccessDeniedException('Only HR managers or Project Owners can delete contracts.');
        }
        if (!$this->isGranted('ROLE_ADMIN') && !$this->isGranted('ROLE_HR') && $contract->getOwner()?->getId() !== $this->getUser()?->getId()) {
            throw $this->createAccessDeniedException('You can only delete your own contracts.');
        }
        if ($this->isCsrfTokenValid('delete' . $contract->getId(), (string) $request->request->get('_token'))) {
            $em->remove($contract);
            $em->flush();
            $this->addFlash('success', 'Contract deleted.');
        }
        return $this->redirectToRoute('app_contract_index');
    }

    #[Route('/{id}/negotiate', name: 'app_contract_negotiate', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function negotiate(Contract $contract, Request $request, EntityManagerInterface $em, ValidatorInterface $validator): Response
    {
        if (!$this->isCsrfTokenValid('negotiate' . $contract->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token.');
            return $this->redirectToRoute('app_contract_show', ['id' => $contract->getId()]);
        }

        $counterAmount = $request->request->get('counter_amount');
        $counterTerms = trim((string) $request->request->get('counter_terms', ''));
        $notes = trim((string) $request->request->get('negotiation_notes', ''));

        // Validate counter amount
        if ($counterAmount !== null && $counterAmount !== '') {
            $counterAmount = (float) $counterAmount;
            if ($counterAmount <= 0) {
                $this->addFlash('error', 'Counter amount must be a positive number.');
                return $this->redirectToRoute('app_contract_show', ['id' => $contract->getId()]);
            }
            if ($counterAmount > 99999999) {
                $this->addFlash('error', 'Counter amount is too large.');
                return $this->redirectToRoute('app_contract_show', ['id' => $contract->getId()]);
            }
            $contract->setCounterAmount((string) $counterAmount);
        }

        // Validate counter terms
        if ($counterTerms !== '') {
            $violations = $validator->validate($counterTerms, [
                new Assert\Length([
                    'min' => 10, 'minMessage' => 'Counter terms must be at least {{ limit }} characters.',
                    'max' => 5000, 'maxMessage' => 'Counter terms cannot exceed {{ limit }} characters.',
                ]),
            ]);
            if (count($violations) > 0) {
                foreach ($violations as $v) { $this->addFlash('error', $v->getMessage()); }
                return $this->redirectToRoute('app_contract_show', ['id' => $contract->getId()]);
            }
            $contract->setCounterTerms($counterTerms);
        }

        // Validate notes
        if ($notes !== '') {
            $violations = $validator->validate($notes, [
                new Assert\Length(['max' => 2000, 'maxMessage' => 'Notes cannot exceed {{ limit }} characters.']),
            ]);
            if (count($violations) > 0) {
                foreach ($violations as $v) { $this->addFlash('error', $v->getMessage()); }
                return $this->redirectToRoute('app_contract_show', ['id' => $contract->getId()]);
            }
            $contract->setNegotiationNotes($notes);
        }

        // Must have at least counter amount or counter terms
        $hasAmount = $counterAmount !== null && $counterAmount !== '' && (is_float($counterAmount) && $counterAmount > 0);
        if ($counterTerms === '' && !$hasAmount) {
            $this->addFlash('error', 'Please provide a counter amount or counter terms.');
            return $this->redirectToRoute('app_contract_show', ['id' => $contract->getId()]);
        }

        $round = ($contract->getNegotiationRound() ?? 0) + 1;
        $contract->setNegotiationRound($round);
        $contract->setStatus('COUNTER_PROPOSED');

        $em->flush();
        $this->addFlash('success', 'Counter-proposal submitted (Round ' . $round . ').');
        return $this->redirectToRoute('app_contract_show', ['id' => $contract->getId()]);
    }

    #[Route('/{id}/sign', name: 'app_contract_sign', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function sign(Contract $contract, Request $request, EntityManagerInterface $em, N8nWebhookService $n8n, NotificationService $notifier, HttpClientInterface $httpClient): Response
    {
        if (!$this->isCsrfTokenValid('sign' . $contract->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token.');
            return $this->redirectToRoute('app_contract_show', ['id' => $contract->getId()]);
        }

        $signatureData = (string) $request->request->get('signature_data', '');

        if ($signatureData === '' || strlen($signatureData) < 100) {
            $this->addFlash('error', 'Please draw your signature before signing.');
            return $this->redirectToRoute('app_contract_show', ['id' => $contract->getId()]);
        }

        // Validate it's a valid data URL
        if (!str_starts_with($signatureData, 'data:image/png;base64,')) {
            $this->addFlash('error', 'Invalid signature data format.');
            return $this->redirectToRoute('app_contract_show', ['id' => $contract->getId()]);
        }

        $contract->setSignatureData($signatureData);
        $contract->initSignedAt(new \DateTime());
        $contract->setStatus('ACTIVE');

        // Generate a simple blockchain-like hash for verification
        $hashPayload = $contract->getId() . '|' . ($contract->getApplicant() ? $contract->getApplicant()->getId() : '') . '|' . date('c') . '|' . substr($signatureData, 0, 200);
        $contract->setBlockchainHash(hash('sha256', $hashPayload));

        // Calculate risk score from contract data
        $risk = $this->calculateRiskScore($contract);
        $contract->setRiskScore($risk['score']);
        $contract->setRiskFactors($risk['factors']);

        $em->flush();

        $candidateName = $contract->getApplicant()
            ? $contract->getApplicant()->getFirstName() . ' ' . $contract->getApplicant()->getLastName()
            : 'N/A';

        $offerTitle = $contract->getOffer() && $contract->getOffer()->getTitle()
            ? (string) $contract->getOffer()->getTitle()
            : 'Contract';

        $n8n->contractSigned(
            (int) $contract->getId(),
            $offerTitle,
            $candidateName,
            (float) ($contract->getAmount() ?? 0)
        );

        $emailSent = false;
        if ($contract->getApplicant()) {
            $notifier->contractSigned($contract->getApplicant(), (int) $contract->getId());
            $emailSent = $this->sendContractSignedEmail($httpClient, $contract);
        }

        if ($emailSent) {
            $this->addFlash('success', 'Contract signed successfully. A confirmation email has been sent to the candidate.');
        } else {
            $this->addFlash('warning', 'Contract signed successfully, but email delivery is delayed or failed. You can retry using Send Email.');
        }
        return $this->redirectToRoute('app_contract_show', ['id' => $contract->getId()]);
    }

    #[Route('/verify/{hash}', name: 'app_contract_verify')]
    public function verify(string $hash, ContractRepository $repo): Response
    {
        $contract = null;
        $valid = false;

        if (preg_match('/^[a-f0-9]{64}$/i', $hash)) {
            $contract = $repo->findOneBy(['blockchain_hash' => $hash]);
            $valid = $contract !== null;
        }

        return $this->render('contract/verify.html.twig', [
            'contract' => $contract,
            'hash'     => $hash,
            'valid'    => $valid,
        ]);
    }

    #[Route('/verify-search', name: 'app_contract_verify_lookup', methods: ['GET'])]
    public function verifyLookup(Request $request): Response
    {
        $rawHash = (string) $request->query->get('hash', '');
        $hash = strtolower(trim(preg_replace('/\s+/', '', $rawHash) ?? ''));

        if ($hash === '') {
            $this->addFlash('error', 'Enter a verification hash to check the contract.');
            return $this->redirectToRoute('app_contract_index', ['hash' => $rawHash]);
        }

        if (!preg_match('/^[a-f0-9]{64}$/i', $hash)) {
            $this->addFlash('error', 'Verification hash must be a valid 64-character SHA-256 value.');
            return $this->redirectToRoute('app_contract_index', ['hash' => $rawHash]);
        }

        return $this->redirectToRoute('app_contract_verify', ['hash' => $hash]);
    }

    #[Route('/{id}/print', name: 'app_contract_print', requirements: ['id' => '\d+'])]
    public function contractPdf(Contract $contract): Response
    {
        $user = $this->getUser();
        if (!$this->isGranted('ROLE_HR') && !$this->isGranted('ROLE_ADMIN')) {
            $userId = $user?->getId();
            if ($contract->getOwner()?->getId() !== $userId && $contract->getApplicant()?->getId() !== $userId) {
                throw $this->createAccessDeniedException('You cannot access this contract.');
            }
        }

        return $this->render('contract/print.html.twig', [
            'contract' => $contract,
        ]);
    }

    #[Route('/{id}/generate-ai-draft', name: 'app_contract_generate_ai_draft', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_HR')]
    public function generateAiDraft(Contract $contract, Request $request, EntityManagerInterface $em, AIService $ai): Response
    {
        if (!$this->isCsrfTokenValid('ai_draft' . $contract->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token.');
            return $this->redirectToRoute('app_contract_show', ['id' => $contract->getId()]);
        }

        $candidateName = $contract->getApplicant()
            ? $contract->getApplicant()->getFirstName() . ' ' . $contract->getApplicant()->getLastName()
            : 'Candidate';
        $offerTitle = $contract->getOffer() && $contract->getOffer()->getTitle()
            ? (string) $contract->getOffer()->getTitle()
            : 'Position';

        $draft = $ai->generateContractDraft(
            $candidateName,
            $offerTitle,
            'GIG',
            (float) ($contract->getAmount() ?? 0),
            $contract->getStartDate(),
            $contract->getEndDate()
        );

        $contract->setTerms($draft);
        $em->flush();

        $this->addFlash('success', 'AI contract draft generated. Review and edit before sending to the candidate.');
        return $this->redirectToRoute('app_contract_edit', ['id' => $contract->getId()]);
    }

    #[Route('/{id}/send-email', name: 'app_contract_send_email', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_HR')]
    public function sendEmail(Contract $contract, Request $request, HttpClientInterface $httpClient): Response
    {
        if (!$this->isCsrfTokenValid('send_email' . $contract->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token.');
            return $this->redirectToRoute('app_contract_show', ['id' => $contract->getId()]);
        }

        if (!$contract->getApplicant() || !$contract->getApplicant()->getEmail()) {
            $this->addFlash('error', 'Candidate has no email address on file.');
            return $this->redirectToRoute('app_contract_show', ['id' => $contract->getId()]);
        }

        $sent = $this->sendContractSignedEmail($httpClient, $contract);
        if ($sent) {
            $this->addFlash('success', 'Contract email sent to ' . $contract->getApplicant()->getEmail() . '.');
        } else {
            $this->addFlash('warning', 'Email could not be delivered right now. Please retry in a moment.');
        }
        return $this->redirectToRoute('app_contract_show', ['id' => $contract->getId()]);
    }

    private function sendContractSignedEmail(HttpClientInterface $httpClient, Contract $contract): bool
    {
        $applicant = $contract->getApplicant();
        if (!$applicant || !$applicant->getEmail()) {
            return false;
        }

        try {
            $resendApiKey = $this->getConfigValue('RESEND_API_KEY');
            $mailjetApiKey = $this->getConfigValue('MAILJET_API_KEY');
            $mailjetSecretKey = $this->getConfigValue('MAILJET_SECRET_KEY');
            $brevoApiKey = $this->getConfigValue('BREVO_API_KEY');

            if ($resendApiKey === '' && ($mailjetApiKey === '' || $mailjetSecretKey === '') && $brevoApiKey === '') {
                error_log('Contract email send skipped: no HTTPS email API credentials configured (Resend/Mailjet/Brevo).');
                return false;
            }

            $fromEmail = $this->getConfigValue('RESEND_FROM_EMAIL');
            if ($fromEmail === '') {
                $fromEmail = $this->getConfigValue('MAILER_FROM');
            }
            if ($fromEmail === '') {
                $fromEmail = $this->getConfigValue('SMTP_EMAIL');
            }
            if ($fromEmail === '') {
                $fromEmail = 'noreply@synergygig.work.gd';
            }
            $fromName = $this->getConfigValue('RESEND_FROM_NAME');
            if ($fromName === '') {
                $fromName = 'SynergyGig';
            }

            $printUrl = $this->generateUrl('app_contract_print', ['id' => $contract->getId()], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL);
            $verifyUrl = $contract->getBlockchainHash()
                ? $this->generateUrl('app_contract_verify', ['hash' => $contract->getBlockchainHash()], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL)
                : null;

            $candidateName = $applicant->getFirstName() . ' ' . $applicant->getLastName();
            $position = $contract->getOffer() ? $contract->getOffer()->getTitle() : 'Contract';
            $amount = $contract->getAmount() ? number_format((float) $contract->getAmount(), 2) . ' ' . ($contract->getCurrency() ?? 'USD') : 'N/A';
            $startDate = $contract->getStartDate() ? $contract->getStartDate()->format('M d, Y') : 'N/A';
            $endDate = $contract->getEndDate() ? $contract->getEndDate()->format('M d, Y') : 'N/A';
            $signedAt = $contract->getSignedAt() ? $contract->getSignedAt()->format('M d, Y H:i') . ' UTC' : 'N/A';
            $hash = $contract->getBlockchainHash() ?? 'N/A';

            $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:system-ui,-apple-system,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:40px 0;">
  <tr><td align="center">
    <table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.08);">
      <!-- Header -->
      <tr><td style="background:linear-gradient(135deg,#1e3a5f 0%,#3b82f6 100%);padding:36px 40px;text-align:center;">
        <div style="font-size:26px;font-weight:800;color:#fff;letter-spacing:-0.5px;">Synergy<span style="color:#93c5fd;">Gig</span></div>
        <div style="font-size:13px;color:#bfdbfe;margin-top:4px;">Contract Confirmation</div>
      </td></tr>
      <!-- Body -->
      <tr><td style="padding:36px 40px;">
        <p style="font-size:16px;color:#1e293b;margin:0 0 12px;">Dear <strong>{$candidateName}</strong>,</p>
        <p style="font-size:14px;color:#475569;margin:0 0 28px;line-height:1.6;">
          Your contract has been successfully signed and is now active. Below are the details of your agreement. Please keep this email for your records.
        </p>

        <!-- Contract Summary -->
        <table width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;margin-bottom:28px;">
          <tr><td style="padding:20px 24px;border-bottom:1px solid #e2e8f0;">
            <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.8px;color:#94a3b8;margin-bottom:2px;">Position / Offer</div>
            <div style="font-size:15px;font-weight:600;color:#0f172a;">{$position}</div>
          </td></tr>
          <tr><td style="padding:16px 24px;border-bottom:1px solid #e2e8f0;">
            <table width="100%"><tr>
              <td width="50%">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.8px;color:#94a3b8;margin-bottom:2px;">Amount</div>
                <div style="font-size:14px;font-weight:600;color:#0f172a;">{$amount}</div>
              </td>
              <td width="50%">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.8px;color:#94a3b8;margin-bottom:2px;">Status</div>
                <div style="font-size:14px;font-weight:700;color:#10b981;">ACTIVE ✓</div>
              </td>
            </tr></table>
          </td></tr>
          <tr><td style="padding:16px 24px;border-bottom:1px solid #e2e8f0;">
            <table width="100%"><tr>
              <td width="50%">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.8px;color:#94a3b8;margin-bottom:2px;">Start Date</div>
                <div style="font-size:14px;color:#0f172a;">{$startDate}</div>
              </td>
              <td width="50%">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.8px;color:#94a3b8;margin-bottom:2px;">End Date</div>
                <div style="font-size:14px;color:#0f172a;">{$endDate}</div>
              </td>
            </tr></table>
          </td></tr>
          <tr><td style="padding:16px 24px;">
            <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.8px;color:#94a3b8;margin-bottom:2px;">Signed At</div>
            <div style="font-size:14px;color:#0f172a;">{$signedAt}</div>
          </td></tr>
        </table>

        <!-- Blockchain Hash -->
        <div style="background:#0f172a;border-radius:8px;padding:16px 20px;margin-bottom:28px;">
          <div style="font-size:10px;text-transform:uppercase;letter-spacing:0.8px;color:#64748b;margin-bottom:6px;">🔒 Blockchain Verification Hash</div>
          <div style="font-family:'Courier New',monospace;font-size:11px;color:#60a5fa;word-break:break-all;">{$hash}</div>
        </div>

        <!-- CTA Buttons -->
        <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:28px;">
          <tr>
            <td align="center" style="padding:0 6px 0 0;">
              <a href="{$printUrl}" style="display:inline-block;background:#3b82f6;color:#fff;text-decoration:none;padding:12px 24px;border-radius:8px;font-size:14px;font-weight:600;">⬇ Download PDF</a>
            </td>
HTML;
            if ($verifyUrl) {
                $html .= <<<HTML
            <td align="center" style="padding:0 0 0 6px;">
              <a href="{$verifyUrl}" style="display:inline-block;background:#10b981;color:#fff;text-decoration:none;padding:12px 24px;border-radius:8px;font-size:14px;font-weight:600;">🔍 Verify Contract</a>
            </td>
HTML;
            }
            $html .= <<<HTML
          </tr>
        </table>

        <p style="font-size:13px;color:#64748b;line-height:1.6;margin:0;">
          If you have any questions about your contract, please contact your HR representative or project owner directly.
        </p>
      </td></tr>
      <!-- Footer -->
      <tr><td style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:20px 40px;text-align:center;">
        <p style="font-size:11px;color:#94a3b8;margin:0;">SynergyGig Platform &middot; This is an automated message, please do not reply.</p>
      </td></tr>
    </table>
  </td></tr>
</table>
</body>
</html>
HTML;

            if ($resendApiKey !== '') {
                $response = $httpClient->request('POST', 'https://api.resend.com/emails', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $resendApiKey,
                        'Content-Type' => 'application/json',
                    ],
                    'json' => [
                        'from' => sprintf('%s <%s>', $fromName, $fromEmail),
                        'to' => [$applicant->getEmail()],
                        'subject' => 'Your Contract is Now Active — SynergyGig',
                        'html' => $html,
                    ],
                    'timeout' => 10,
                ]);

                $status = $response->getStatusCode();
                if ($status >= 200 && $status < 300) {
                    return true;
                }
                error_log('Resend contract email non-2xx for contract #' . $contract->getId() . ': HTTP ' . $status . ' ' . substr($response->getContent(false), 0, 300));
            }

            if ($mailjetApiKey !== '' && $mailjetSecretKey !== '') {
                $response = $httpClient->request('POST', 'https://api.mailjet.com/v3.1/send', [
                    'auth_basic' => [$mailjetApiKey, $mailjetSecretKey],
                    'headers' => [
                        'Content-Type' => 'application/json',
                    ],
                    'json' => [
                        'Messages' => [[
                            'From' => ['Email' => $fromEmail, 'Name' => $fromName],
                            'To' => [['Email' => $applicant->getEmail(), 'Name' => $candidateName]],
                            'ReplyTo' => ['Email' => $this->getConfigValue('MAILER_REPLY_TO') ?: $fromEmail, 'Name' => $fromName],
                            'Subject' => 'Your Contract is Now Active — SynergyGig',
                            'HTMLPart' => $html,
                            'TextPart' => 'Your contract is now active. Download PDF: ' . $printUrl,
                        ]],
                    ],
                    'timeout' => 10,
                ]);

                $status = $response->getStatusCode();
                if ($status >= 200 && $status < 300) {
                    return true;
                }
                error_log('Mailjet contract email non-2xx for contract #' . $contract->getId() . ': HTTP ' . $status . ' ' . substr($response->getContent(false), 0, 300));
            }

            if ($brevoApiKey !== '') {
                $response = $httpClient->request('POST', 'https://api.brevo.com/v3/smtp/email', [
                    'headers' => [
                        'api-key' => $brevoApiKey,
                        'Content-Type' => 'application/json',
                    ],
                    'json' => [
                        'sender' => [
                            'name' => $fromName,
                            'email' => $fromEmail,
                        ],
                        'to' => [[
                            'email' => $applicant->getEmail(),
                            'name' => $candidateName,
                        ]],
                        'subject' => 'Your Contract is Now Active — SynergyGig',
                        'htmlContent' => $html,
                    ],
                    'timeout' => 10,
                ]);

                $status = $response->getStatusCode();
                if ($status >= 200 && $status < 300) {
                    return true;
                }
                error_log('Brevo contract email non-2xx for contract #' . $contract->getId() . ': HTTP ' . $status . ' ' . substr($response->getContent(false), 0, 300));
            }

            error_log('Contract email send failed for contract #' . $contract->getId() . ': all configured providers returned non-2xx.');
            return false;
        } catch (\Throwable $e) {
            error_log('Contract email send failed for contract #' . $contract->getId() . ': ' . $e->getMessage());
            return false;
        }
    }

    private function getConfigValue(string $key): string
    {
        $fromEnv = $_ENV[$key] ?? getenv($key) ?: '';
        if (is_string($fromEnv) && trim($fromEnv) !== '') {
            return trim($fromEnv);
        }

        $envFile = dirname(__DIR__, 2) . '/.env';
        if (!is_file($envFile) || !is_readable($envFile)) {
            return '';
        }

        $lines = @file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return '';
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!str_starts_with($line, $key . '=')) {
                continue;
            }

            $value = trim(substr($line, strlen($key) + 1));
            $value = trim($value, "\"'");
            return $value;
        }

        return '';
    }

    /** @return array{score:int,factors:string} */
    private function calculateRiskScore(Contract $contract): array{
        $score = 0;
        $factors = [];

        if (!$contract->getAmount() || $contract->getAmount() <= 0) {
            $score += 25;
            $factors[] = 'No amount specified';
        } elseif ($contract->getAmount() > 100000) {
            $score += 15;
            $factors[] = 'High value contract';
        }

        if (!$contract->getEndDate()) {
            $score += 20;
            $factors[] = 'No end date defined';
        } elseif ($contract->getStartDate() && $contract->getEndDate()) {
            $diff = $contract->getStartDate()->diff($contract->getEndDate())->days;
            if ($diff > 365) {
                $score += 10;
                $factors[] = 'Long duration (' . $diff . ' days)';
            }
        }

        if (!$contract->getTerms() || strlen($contract->getTerms()) < 50) {
            $score += 20;
            $factors[] = 'Insufficient terms detail';
        }

        if ($contract->getNegotiationRound() && $contract->getNegotiationRound() > 3) {
            $score += 10;
            $factors[] = 'Multiple negotiation rounds';
        }

        return [
            'score' => min(100, $score),
            'factors' => implode('; ', $factors ?: ['No risk factors identified']),
        ];
    }
}
