<?php

namespace App\Controller;

use App\Entity\Call;
use App\Entity\CallSignal;
use App\Repository\CallRepository;
use App\Repository\CallSignalRepository;
use App\Repository\UserRepository;
use App\Repository\ChatRoomRepository;
use App\Entity\User;
use App\Repository\ChatRoomMemberRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

#[Route('/call')]
class CallController extends AbstractController
{
    /* ──────────────────────────────
       CALL HISTORY
       ────────────────────────────── */
    #[Route('/', name: 'app_call_index')]
    public function index(CallRepository $callRepo): Response
    {
        $user = $this->getUser();
        $calls = $callRepo->findCallHistory($user, 100);

        return $this->render('call/index.html.twig', [
            'calls' => $calls,
        ]);
    }

    /* ──────────────────────────────
       START CALL (AJAX from chat)
       ────────────────────────────── */
    #[Route('/start-ajax/{roomId}/{type}', name: 'app_call_start_ajax', methods: ['POST'])]
    public function startAjax(
        int $roomId,
        string $type,
        Request $request,
        CallRepository $callRepo,
        ChatRoomRepository $roomRepo,
        ChatRoomMemberRepository $memberRepo,
        EntityManagerInterface $em
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }
        $room = $roomRepo->find($roomId);
        if (!$room) {
            return $this->json(['error' => 'Room not found'], 404);
        }

        // End any existing active call first
        $active = $callRepo->findActiveCall($user);
        if ($active) {
            $active->setStatus('ENDED');
            $active->initEndedAt(new \DateTime());
            $em->flush();
        }

        $call = new Call();
        $call->setCaller($user);
        $call->setRoomId($roomId);
        $call->setCallType(strtoupper($type) === 'VIDEO' ? 'VIDEO' : 'AUDIO');
        $call->setStatus('RINGING');
        $calleeName = null;
        if ($room->getType() === 'DIRECT') {
            $otherMember = $memberRepo->findOtherMember($room, $user);
            if ($otherMember && $otherMember->getUser()) {
                $callee = $otherMember->getUser();
                $call->setCallee($callee);
                $calleeName = (string) $callee->getFirstName() . ' ' . (string) $callee->getLastName();
            }
        }

        $em->persist($call);
        $em->flush();

        return $this->json([
            'callId'    => $call->getId(),
            'callType'  => $call->getCallType(),
            'calleeName' => $calleeName,
            'isCaller'  => true,
        ]);
    }

    /* ──────────────────────────────
       POPUP WINDOW (standalone)
       ────────────────────────────── */
    #[Route('/popup/{id}', name: 'app_call_popup', requirements: ['id' => '\d+'])]
    public function popup(Call $call, CsrfTokenManagerInterface $csrfTokenManager): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        $isCaller = $call->getCaller() && $call->getCaller()->getId() === $user->getId();

        $callee = $call->getCallee();
        $caller = $call->getCaller();

        // Determine the other person's name
        if ($isCaller) {
            $peerName = $callee ? (string) $callee->getFirstName() . ' ' . (string) $callee->getLastName() : 'Unknown';
            $peerInitials = $callee ? substr((string) $callee->getFirstName(), 0, 1) . substr((string) $callee->getLastName(), 0, 1) : '?';
        } else {
            $peerName = $caller ? (string) $caller->getFirstName() . ' ' . (string) $caller->getLastName() : 'Unknown';
            $peerInitials = $caller ? substr((string) $caller->getFirstName(), 0, 1) . substr((string) $caller->getLastName(), 0, 1) : '?';
        }

        return $this->render('call/popup.html.twig', [
            'call'         => $call,
            'isCaller'     => $isCaller,
            'peerName'     => $peerName,
            'peerInitials' => strtoupper($peerInitials),
            'csrfToken'    => $csrfTokenManager->getToken('call_action' . $call->getId())->getValue(),
        ]);
    }

    /* ──────────────────────────────
       ACCEPT CALL (AJAX)
       ────────────────────────────── */
    #[Route('/{id}/accept-ajax', name: 'app_call_accept_ajax', methods: ['POST'])]
    public function acceptAjax(Call $call, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }
        if ($call->getStatus() === 'RINGING') {
            $call->setStatus('CONNECTED');
            $call->initStartedAt(new \DateTime());
            if (!$call->getCallee()) {
                $call->setCallee($user);
            }
            $em->flush();
        }
        return $this->json(['status' => $call->getStatus()]);
    }

    /* ──────────────────────────────
       REJECT CALL (AJAX)
       ────────────────────────────── */
    #[Route('/{id}/reject-ajax', name: 'app_call_reject_ajax', methods: ['POST'])]
    public function rejectAjax(Call $call, EntityManagerInterface $em): JsonResponse
    {
        if ($call->getStatus() === 'RINGING') {
            $call->setStatus('REJECTED');
            $call->initEndedAt(new \DateTime());
            $em->flush();
        }
        return $this->json(['status' => $call->getStatus()]);
    }

    /* ──────────────────────────────
       END CALL (AJAX)
       ────────────────────────────── */
    #[Route('/{id}/end-ajax', name: 'app_call_end_ajax', methods: ['POST'])]
    public function endAjax(
        Call $call,
        EntityManagerInterface $em,
        CallSignalRepository $signalRepo
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }
        $userId = $user->getId();
        if ($call->getCaller()?->getId() !== $userId && $call->getCallee()?->getId() !== $userId) {
            return $this->json(['error' => 'Unauthorized'], 403);
        }
        if (in_array($call->getStatus(), ['RINGING', 'CONNECTED'])) {
            $call->setStatus('ENDED');
            $call->initEndedAt(new \DateTime());
            $em->flush();
            // Clean up signals
            if ($call->getId() !== null) {
                $signalRepo->deleteForCall($call->getId());
            }
        }
        return $this->json(['status' => $call->getStatus()]);
    }

    /* ──────────────────────────────
       SEND SIGNAL (SDP / ICE)
       ────────────────────────────── */
    #[Route('/signal/send', name: 'app_call_signal_send', methods: ['POST'])]
    public function signalSend(Request $request, EntityManagerInterface $em, CallRepository $callRepo): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }
        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !isset($data['callId'], $data['type'], $data['payload'])) {
            return $this->json(['error' => 'Missing fields'], 400);
        }

        $call = $callRepo->find($data['callId']);
        if (!$call) {
            return $this->json(['error' => 'Call not found'], 404);
        }

        $signal = new CallSignal();
        $signal->setCall($call);
        $signal->setFromUser($user);
        $signal->setSignalType((string) $data['type']);
        $payload = json_encode($data['payload']);
        if ($payload === false) {
            return $this->json(['error' => 'Invalid payload'], 400);
        }
        $signal->setPayload($payload);
        $em->persist($signal);
        $em->flush();

        return $this->json(['ok' => true, 'signalId' => $signal->getId()]);
    }

    /* ──────────────────────────────
       POLL SIGNALS
       ────────────────────────────── */
    #[Route('/signal/poll/{callId}', name: 'app_call_signal_poll', requirements: ['callId' => '\d+'])]
    public function signalPoll(
        int $callId,
        Request $request,
        CallSignalRepository $signalRepo,
        CallRepository $callRepo
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }
        $afterId = (int) $request->query->get('after', 0);

        $call = $callRepo->find($callId);
        if (!$call) {
            return $this->json(['error' => 'Call not found'], 404);
        }

        $userId = $user->getId();
        if ($userId === null) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }
        $signals = $signalRepo->findNewSignals($callId, $userId, $afterId);

        return $this->json([
            'callStatus' => $call->getStatus(),
            'signals'    => array_map(fn(CallSignal $s) => [
                'id'      => $s->getId(),
                'type'    => $s->getSignalType(),
                'payload' => json_decode((string) $s->getPayload(), true),
            ], $signals),
        ]);
    }

    /* ──────────────────────────────
       ACTIVE CALL STATUS (JSON poll)
       ────────────────────────────── */
    #[Route('/status', name: 'app_call_status')]
    public function status(CallRepository $callRepo): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['active' => null, 'incoming' => []]);
        }
        $active = $callRepo->findActiveCall($user);
        $incoming = $callRepo->findIncomingForUser($user);

        return $this->json([
            'active' => $active ? [
                'id'         => $active->getId(),
                'status'     => $active->getStatus(),
                'type'       => $active->getCallType(),
                'callerId'   => $active->getCaller()?->getId(),
                'callerName' => $active->getCaller() ? $active->getCaller()->getFirstName() . ' ' . $active->getCaller()->getLastName() : null,
            ] : null,
            'incoming' => array_map(fn(Call $c) => [
                'id'         => $c->getId(),
                'type'       => $c->getCallType(),
                'callerName' => $c->getCaller() ? $c->getCaller()->getFirstName() . ' ' . $c->getCaller()->getLastName() : 'Unknown',
            ], $incoming),
        ]);
    }

    /* ──────────────────────────────
       LEGACY: CALL ROOM (redirect to popup)
       ────────────────────────────── */
    #[Route('/room/{id}', name: 'app_call_room', requirements: ['id' => '\d+'])]
    public function room(Call $call): Response
    {
        return $this->redirectToRoute('app_call_popup', ['id' => $call->getId()]);
    }

    /* ──────────────────────────────
       LEGACY: START (redirect-based, kept for compat)
       ────────────────────────────── */
    #[Route('/start/{roomId}/{type}', name: 'app_call_start', requirements: ['roomId' => '\d+'])]
    public function start(
        int $roomId,
        string $type,
        CallRepository $callRepo,
        ChatRoomRepository $roomRepo,
        ChatRoomMemberRepository $memberRepo,
        EntityManagerInterface $em
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            $this->addFlash('warning', 'You must be logged in.');
            return $this->redirectToRoute('app_chat_index');
        }
        $room = $roomRepo->find($roomId);
        if (!$room) {
            $this->addFlash('warning', 'Room not found.');
            return $this->redirectToRoute('app_chat_index');
        }

        $active = $callRepo->findActiveCall($user);
        if ($active) {
            $active->setStatus('ENDED');
            $active->initEndedAt(new \DateTime());
            $em->flush();
        }

        $call = new Call();
        $call->setCaller($user);
        $call->setRoomId($roomId);
        $call->setCallType(strtoupper($type) === 'VIDEO' ? 'VIDEO' : 'AUDIO');
        $call->setStatus('RINGING');
        if ($room->getType() === 'DIRECT') {
            $otherMember = $memberRepo->findOtherMember($room, $user);
            if ($otherMember) {
                $call->setCallee($otherMember->getUser());
            }
        }

        $em->persist($call);
        $em->flush();

        return $this->redirectToRoute('app_call_popup', ['id' => $call->getId()]);
    }

    /* ── Legacy form-based endpoints (kept for backwards compat) ── */

    #[Route('/{id}/accept', name: 'app_call_accept', methods: ['POST'])]
    public function accept(Call $call, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('call_action' . $call->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_call_index');
        }
        $currentUser = $this->getUser();
        if ($call->getStatus() === 'RINGING') {
            $call->setStatus('CONNECTED');
            $call->initStartedAt(new \DateTime());
            if (!$call->getCallee() && $currentUser instanceof User) {
                $call->setCallee($currentUser);
            }
            $em->flush();
        }
        return $this->redirectToRoute('app_call_popup', ['id' => $call->getId()]);
    }

    #[Route('/{id}/reject', name: 'app_call_reject', methods: ['POST'])]
    public function reject(Call $call, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('call_action' . $call->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_call_index');
        }
        if ($call->getStatus() === 'RINGING') {
            $call->setStatus('REJECTED');
            $call->initEndedAt(new \DateTime());
            $em->flush();
        }
        return $this->redirectToRoute('app_call_index');
    }

    #[Route('/{id}/end', name: 'app_call_end', methods: ['POST'])]
    public function end(Call $call, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('call_action' . $call->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_call_index');
        }
        $user = $this->getUser();
        if (!$user instanceof User) {
            $this->addFlash('danger', 'You must be logged in.');
            return $this->redirectToRoute('app_call_index');
        }
        if ($call->getCaller() !== $user && $call->getCallee() !== $user) {
            $this->addFlash('danger', 'You are not part of this call.');
            return $this->redirectToRoute('app_call_index');
        }
        if (in_array($call->getStatus(), ['RINGING', 'CONNECTED'])) {
            $call->setStatus('ENDED');
            $call->initEndedAt(new \DateTime());
            $em->flush();
        }
        return $this->redirectToRoute('app_call_index');
    }
}
