<?php

namespace App\Controller\API;

use App\Entity\Call;
use App\Repository\CallRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * REST API endpoints for cross-platform call signaling.
 * Used by the Java desktop app (JDBC mode) which cannot use session cookies.
 * Protected by JWT (same as other /api routes).
 */
#[Route('/api/calls')]
class ApiCallController extends AbstractController
{
    /** @return array<string, mixed> */
    private function callToArray(Call $c): array
    {
        return [
            'id'         => $c->getId(),
            'caller_id'  => $c->getCaller()?->getId(),
            'callee_id'  => $c->getCallee()?->getId(),
            'room_id'    => $c->getRoomId(),
            'status'     => $c->getStatus(),
            'call_type'  => $c->getCallType() ?? 'AUDIO',
            'started_at' => $c->getStartedAt()?->format('Y-m-d H:i:s'),
            'ended_at'   => $c->getEndedAt()?->format('Y-m-d H:i:s'),
            'created_at' => $c->getCreatedAt()?->format('Y-m-d H:i:s'),
            'caller_name' => $c->getCaller()
                ? $c->getCaller()->getFirstName() . ' ' . $c->getCaller()->getLastName()
                : 'Unknown',
        ];
    }

    /* ──────────────────────────────
       GET /api/calls/incoming/{userId}
       Returns the oldest RINGING call where callee = userId
       ────────────────────────────── */
    #[Route('/incoming/{userId}', name: 'api_calls_incoming', methods: ['GET'], requirements: ['userId' => '\d+'])]
    public function incoming(int $userId, CallRepository $callRepo, UserRepository $userRepo): JsonResponse
    {
        $calls = $callRepo->findIncomingForUserById($userId);
        if (empty($calls)) {
            return $this->json(null);
        }
        return $this->json($this->callToArray($calls[0]));
    }

    /* ──────────────────────────────
       GET /api/calls/active/{userId}
       Returns the active (RINGING or CONNECTED) call for a user
       ────────────────────────────── */
    #[Route('/active/{userId}', name: 'api_calls_active', methods: ['GET'], requirements: ['userId' => '\d+'])]
    public function active(int $userId, CallRepository $callRepo): JsonResponse
    {
        $call = $callRepo->findActiveCallById($userId);
        if (!$call) {
            return $this->json(null);
        }
        return $this->json($this->callToArray($call));
    }

    /* ──────────────────────────────
       GET /api/calls/{id}
       ────────────────────────────── */
    #[Route('/{id}', name: 'api_calls_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id, CallRepository $callRepo): JsonResponse
    {
        $call = $callRepo->find($id);
        if (!$call) {
            return $this->json(['error' => 'Not found'], 404);
        }
        return $this->json($this->callToArray($call));
    }

    /* ──────────────────────────────
       POST /api/calls
       Body: { caller_id, callee_id, room_id, call_type }
       ────────────────────────────── */
    #[Route('', name: 'api_calls_create', methods: ['POST'])]
    public function create(Request $request, UserRepository $userRepo, CallRepository $callRepo, EntityManagerInterface $em): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!isset($data['caller_id'], $data['callee_id'])) {
            return $this->json(['error' => 'caller_id and callee_id required'], 400);
        }

        $caller = $userRepo->find((int) $data['caller_id']);
        $callee = $userRepo->find((int) $data['callee_id']);
        if (!$caller || !$callee) {
            return $this->json(['error' => 'User not found'], 404);
        }

        // End any existing active call for the caller
        $active = $callRepo->findActiveCallById((int) $caller->getId());
        if ($active) {
            $active->setStatus('ENDED');
            $active->initEndedAt(new \DateTime());
        }

        $call = new Call();
        $call->setCaller($caller);
        $call->setCallee($callee);
        $call->setRoomId(isset($data['room_id']) ? (int) $data['room_id'] : null);
        $call->setCallType(strtoupper($data['call_type'] ?? 'AUDIO'));
        $call->setStatus('RINGING');
        $call->initCreatedAt();

        $em->persist($call);
        $em->flush();

        return $this->json($this->callToArray($call), 201);
    }

    /* ──────────────────────────────
       PUT /api/calls/{id}/accept
       ────────────────────────────── */
    #[Route('/{id}/accept', name: 'api_calls_accept', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function accept(int $id, CallRepository $callRepo, EntityManagerInterface $em): JsonResponse
    {
        $call = $callRepo->find($id);
        if (!$call) return $this->json(['error' => 'Not found'], 404);

        $call->setStatus('CONNECTED');
        $call->initStartedAt(new \DateTime());
        $em->flush();

        return $this->json($this->callToArray($call));
    }

    /* ──────────────────────────────
       PUT /api/calls/{id}/reject
       ────────────────────────────── */
    #[Route('/{id}/reject', name: 'api_calls_reject', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function reject(int $id, CallRepository $callRepo, EntityManagerInterface $em): JsonResponse
    {
        $call = $callRepo->find($id);
        if (!$call) return $this->json(['error' => 'Not found'], 404);

        $call->setStatus('REJECTED');
        $call->initEndedAt(new \DateTime());
        $em->flush();

        return $this->json($this->callToArray($call));
    }

    /* ──────────────────────────────
       PUT /api/calls/{id}/end
       ────────────────────────────── */
    #[Route('/{id}/end', name: 'api_calls_end', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function end(int $id, CallRepository $callRepo, EntityManagerInterface $em): JsonResponse
    {
        $call = $callRepo->find($id);
        if (!$call) return $this->json(['error' => 'Not found'], 404);

        $call->setStatus('ENDED');
        $call->initEndedAt(new \DateTime());
        $em->flush();

        return $this->json($this->callToArray($call));
    }
}
