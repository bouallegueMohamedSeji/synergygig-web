<?php

namespace App\Controller;

use App\Entity\User;
use App\Entity\ChatRoom;
use App\Entity\ChatRoomMember;
use App\Entity\Message;
use App\Repository\ChatRoomRepository;
use App\Repository\ChatRoomMemberRepository;
use App\Repository\MessageRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\String\Slugger\SluggerInterface;

#[Route('/chat')]
class ChatController extends AbstractController
{
    private function getAuthenticatedUser(): ?User
    {
        $user = $this->getUser();
        return $user instanceof User ? $user : null;
    }

    /* ──────────────────────────────
       ROOM LIST (Sidebar)
       ────────────────────────────── */
    #[Route('/', name: 'app_chat_index')]
    public function index(
        ChatRoomMemberRepository $memberRepo,
        MessageRepository $msgRepo,
        UserRepository $userRepo
    ): Response {
        $user = $this->getAuthenticatedUser();
        if (!$user) {
            $this->addFlash('danger', 'You must be logged in to access chat.');
            return $this->redirectToRoute('app_login');
        }
        $memberships = $memberRepo->findBy(['user' => $user]);
        $rooms = [];
        /** @var ChatRoomMember $m */
        foreach ($memberships as $m) {
            $room = $m->getRoom();
            if (!$room) continue;
            $lastMsg = $msgRepo->findOneBy(['room' => $room], ['timestamp' => 'DESC']);

            // For DMs, resolve the other user's name
            $displayName = $room->getName();
            if ($room->getType() === 'DIRECT') {
                $otherMember = $memberRepo->findOtherMember($room, $user);
                if ($otherMember && $otherMember->getUser()) {
                    $other = $otherMember->getUser();
                    $displayName = (string) $other->getFirstName() . ' ' . (string) $other->getLastName();
                }
            }

            $rooms[] = [
                'room' => $room,
                'displayName' => $displayName,
                'lastMessage' => $lastMsg,
                'role' => $m->getRole(),
            ];
        }

        // Sort by last message timestamp DESC
        usort($rooms, function ($a, $b) {
            $ta = $a['lastMessage'] ? $a['lastMessage']->getTimestamp() : $a['room']->getCreatedAt();
            $tb = $b['lastMessage'] ? $b['lastMessage']->getTimestamp() : $b['room']->getCreatedAt();
            return $tb <=> $ta;
        });

        return $this->render('chat/index.html.twig', [
            'rooms' => $rooms,
            'activeRoom' => null,
            'messages' => [],
            'users' => $userRepo->findBy([], ['id' => 'DESC'], 200),
        ]);
    }

    /* ──────────────────────────────
       ROOM VIEW (Messages)
       ────────────────────────────── */
    #[Route('/room/{id}', name: 'app_chat_room', requirements: ['id' => '\d+'])]
    public function room(
        ChatRoom $activeRoom,
        ChatRoomMemberRepository $memberRepo,
        MessageRepository $msgRepo,
        UserRepository $userRepo
    ): Response {
        $user = $this->getAuthenticatedUser();
        if (!$user) {
            $this->addFlash('danger', 'You must be logged in to access chat.');
            return $this->redirectToRoute('app_login');
        }

        // Verify user is a member of this room
        $isMember = $memberRepo->findOneBy(['room' => $activeRoom, 'user' => $user]);
        if (!$isMember) {
            $this->addFlash('danger', 'You are not a member of this room.');
            return $this->redirectToRoute('app_chat_index');
        }

        $memberships = $memberRepo->findBy(['user' => $user]);

        $rooms = [];
        /** @var ChatRoomMember $m */
        foreach ($memberships as $m) {
            $room = $m->getRoom();
            if (!$room) continue;
            $lastMsg = $msgRepo->findOneBy(['room' => $room], ['timestamp' => 'DESC']);

            $displayName = $room->getName();
            if ($room->getType() === 'DIRECT') {
                $otherMember = $memberRepo->findOtherMember($room, $user);
                if ($otherMember && $otherMember->getUser()) {
                    $other = $otherMember->getUser();
                    $displayName = (string) $other->getFirstName() . ' ' . (string) $other->getLastName();
                }
            }

            $rooms[] = [
                'room' => $room,
                'displayName' => $displayName,
                'lastMessage' => $lastMsg,
                'role' => $m->getRole(),
            ];
        }

        usort($rooms, function ($a, $b) {
            $ta = $a['lastMessage'] ? $a['lastMessage']->getTimestamp() : $a['room']->getCreatedAt();
            $tb = $b['lastMessage'] ? $b['lastMessage']->getTimestamp() : $b['room']->getCreatedAt();
            return $tb <=> $ta;
        });

        // Get display name for the active room header
        $activeDisplayName = $activeRoom->getName();
        if ($activeRoom->getType() === 'DIRECT') {
            $otherMember = $memberRepo->findOtherMember($activeRoom, $user);
            if ($otherMember && $otherMember->getUser()) {
                $other = $otherMember->getUser();
                $activeDisplayName = (string) $other->getFirstName() . ' ' . (string) $other->getLastName();
            }
        }

        $messages = $msgRepo->findBy(['room' => $activeRoom], ['timestamp' => 'ASC']);
        $members = $memberRepo->findBy(['room' => $activeRoom]);

        return $this->render('chat/index.html.twig', [
            'rooms' => $rooms,
            'activeRoom' => $activeRoom,
            'activeDisplayName' => $activeDisplayName,
            'messages' => $messages,
            'members' => $members,
            'users' => $userRepo->findBy([], ['id' => 'DESC'], 200),
        ]);
    }

    /* ──────────────────────────────
       SEND MESSAGE
       ────────────────────────────── */
    #[Route('/room/{id}/send', name: 'app_chat_send', methods: ['POST'])]
    public function send(
        ChatRoom $room,
        Request $request,
        EntityManagerInterface $em,
        SluggerInterface $slugger,
        ChatRoomMemberRepository $memberRepo
    ): Response {
        // Verify user is a member of this room
        $user = $this->getAuthenticatedUser();
        if (!$user) {
            $this->addFlash('danger', 'You must be logged in to access chat.');
            return $this->redirectToRoute('app_login');
        }
        $membership = $memberRepo->findOneBy(['room' => $room, 'user' => $user]);
        if (!$membership) {
            $this->addFlash('danger', 'You are not a member of this room.');
            return $this->redirectToRoute('app_chat_index');
        }

        if (!$this->isCsrfTokenValid('chat_send', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_chat_room', ['id' => $room->getId()]);
        }
        $content = trim((string) $request->request->get('content', ''));
        $file = $request->files->get('attachment');

        if ($content !== '' || $file) {
            $msg = new Message();
            $msg->setSender($user);
            $msg->setRoom($room);
            $msg->setContent($content !== '' ? $content : null);
            $msg->initTimestamp(new \DateTime());

            if ($file) {
                $originalName = $file->getClientOriginalName();
                $safeName = $slugger->slug((string) pathinfo($originalName, PATHINFO_FILENAME));
                $newFilename = $safeName . '-' . uniqid() . '.' . $file->guessExtension();
                $projectDir = $this->getParameter('kernel.project_dir');
                if (!is_string($projectDir)) {
                    throw new \RuntimeException('Invalid project directory configuration.');
                }
                $uploadDir = $projectDir . '/public/uploads/chat';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0775, true);
                }
                try {
                    $file->move($uploadDir, $newFilename);
                    $msg->setAttachment($newFilename);
                    $msg->setAttachmentOriginalName($originalName);
                } catch (FileException $e) {
                    $this->addFlash('warning', 'File upload failed.');
                }
            }

            $em->persist($msg);
            $em->flush();
        }
        return $this->redirectToRoute('app_chat_room', ['id' => $room->getId()]);
    }

    /* ──────────────────────────────
       CREATE GROUP ROOM
       ────────────────────────────── */
    #[Route('/create-room', name: 'app_chat_create_room', methods: ['POST'])]
    public function createRoom(
        Request $request,
        EntityManagerInterface $em
    ): Response {
        $user = $this->getAuthenticatedUser();
        if (!$user) {
            $this->addFlash('danger', 'You must be logged in to access chat.');
            return $this->redirectToRoute('app_login');
        }
        $name = trim((string) $request->request->get('name', ''));
        if ($name === '') {
            $this->addFlash('warning', 'Room name is required.');
            return $this->redirectToRoute('app_chat_index');
        }

        $room = new ChatRoom();
        $room->setName($name);
        $room->setType('GROUP');
        $room->initCreatedBy($user);
        $em->persist($room);

        // Add creator as OWNER
        $member = new ChatRoomMember();
        $member->setRoom($room);
        $member->setUser($user);
        $member->setRole('OWNER');
        $member->initJoinedAt(new \DateTime());
        $em->persist($member);

        $em->flush();
        $this->addFlash('success', 'Room "' . $name . '" created.');
        return $this->redirectToRoute('app_chat_room', ['id' => $room->getId()]);
    }

    /* ──────────────────────────────
       START / GET DM
       ────────────────────────────── */
    #[Route('/dm/{id}', name: 'app_chat_dm', requirements: ['id' => '\d+'])]
    public function directMessage(
        int $id,
        UserRepository $userRepo,
        ChatRoomMemberRepository $memberRepo,
        EntityManagerInterface $em
    ): Response {
        $user = $this->getAuthenticatedUser();
        if (!$user) {
            $this->addFlash('danger', 'You must be logged in to access chat.');
            return $this->redirectToRoute('app_login');
        }
        $other = $userRepo->find($id);
        if (!$other || $other->getId() === $user->getId()) {
            $this->addFlash('warning', 'Invalid user.');
            return $this->redirectToRoute('app_chat_index');
        }

        // Check if DM room already exists
        $existingRoom = $memberRepo->findDirectRoom($user, $other);
        if ($existingRoom) {
            return $this->redirectToRoute('app_chat_room', ['id' => $existingRoom->getId()]);
        }

        // Create new DM room
        $dmName = 'dm_' . min($user->getId(), $other->getId()) . '_' . max($user->getId(), $other->getId());
        $room = new ChatRoom();
        $room->setName($dmName);
        $room->setType('DIRECT');
        $room->initCreatedBy($user);
        $em->persist($room);

        $m1 = new ChatRoomMember();
        $m1->setRoom($room);
        $m1->setUser($user);
        $m1->setRole('MEMBER');
        $m1->initJoinedAt(new \DateTime());
        $em->persist($m1);

        $m2 = new ChatRoomMember();
        $m2->setRoom($room);
        $m2->setUser($other);
        $m2->setRole('MEMBER');
        $m2->initJoinedAt(new \DateTime());
        $em->persist($m2);

        $em->flush();
        return $this->redirectToRoute('app_chat_room', ['id' => $room->getId()]);
    }

    /* ──────────────────────────────
       ADD MEMBER TO GROUP
       ────────────────────────────── */
    #[Route('/room/{id}/add-member', name: 'app_chat_add_member', methods: ['POST'])]
    public function addMember(
        ChatRoom $room,
        Request $request,
        UserRepository $userRepo,
        ChatRoomMemberRepository $memberRepo,
        EntityManagerInterface $em
    ): Response {
        // Only room OWNER or ADMIN can add members
        $currentUser = $this->getAuthenticatedUser();
        if (!$currentUser) {
            $this->addFlash('danger', 'You must be logged in to access chat.');
            return $this->redirectToRoute('app_login');
        }
        /** @var ChatRoomMember|null $currentMembership */
        $currentMembership = $memberRepo->findOneBy(['room' => $room, 'user' => $currentUser]);
        if (!$this->isGranted('ROLE_ADMIN') && (!$currentMembership || $currentMembership->getRole() !== 'OWNER')) {
            $this->addFlash('warning', 'Only the room owner can add members.');
            return $this->redirectToRoute('app_chat_room', ['id' => $room->getId()]);
        }

        $userId = (int) $request->request->get('user_id');
        $target = $userRepo->find($userId);
        if (!$target) {
            $this->addFlash('warning', 'User not found.');
            return $this->redirectToRoute('app_chat_room', ['id' => $room->getId()]);
        }

        // Check not already a member
        $existing = $memberRepo->findOneBy(['room' => $room, 'user' => $target]);
        if ($existing) {
            $this->addFlash('warning', 'User is already a member.');
            return $this->redirectToRoute('app_chat_room', ['id' => $room->getId()]);
        }

        $member = new ChatRoomMember();
        $member->setRoom($room);
        $member->setUser($target);
        $member->setRole('MEMBER');
        $member->initJoinedAt(new \DateTime());
        $em->persist($member);
        $em->flush();

        $this->addFlash('success', $target->getFirstName() . ' added to room.');
        return $this->redirectToRoute('app_chat_room', ['id' => $room->getId()]);
    }

    /* ──────────────────────────────
       EDIT MESSAGE
       ────────────────────────────── */
    #[Route('/message/{id}/edit', name: 'app_chat_edit_message', methods: ['POST'])]
    public function editMessage(
        Message $message,
        Request $request,
        EntityManagerInterface $em
    ): Response {
        $roomId = $message->getRoom()?->getId();
        if ($roomId === null) {
            return $this->redirectToRoute('app_chat_index');
        }
        $currentUser = $this->getAuthenticatedUser();
        if (!$currentUser) {
            $this->addFlash('danger', 'You must be logged in to access chat.');
            return $this->redirectToRoute('app_login');
        }
        if ($message->getSender()?->getId() !== $currentUser->getId()) {
            $this->addFlash('warning', 'Cannot edit other users\' messages.');
            return $this->redirectToRoute('app_chat_room', ['id' => $roomId]);
        }
        $newContent = trim((string) $request->request->get('content', ''));
        if ($newContent !== '') {
            $message->setContent($newContent);
            $message->setIsEdited(true);
            $em->flush();
        }
        return $this->redirectToRoute('app_chat_room', ['id' => $roomId]);
    }

    /* ──────────────────────────────
       DELETE MESSAGE
       ────────────────────────────── */
    #[Route('/message/{id}/delete', name: 'app_chat_delete_message', methods: ['POST'])]
    public function deleteMessage(
        Message $message,
        Request $request,
        EntityManagerInterface $em
    ): Response {
        $roomId = $message->getRoom()?->getId();
        if ($roomId === null) {
            return $this->redirectToRoute('app_chat_index');
        }
        $currentUser = $this->getAuthenticatedUser();
        if (!$currentUser) {
            $this->addFlash('danger', 'You must be logged in to access chat.');
            return $this->redirectToRoute('app_login');
        }
        // Only the sender or an admin can delete a message
        if ($message->getSender()?->getId() !== $currentUser->getId() && !$this->isGranted('ROLE_ADMIN')) {
            $this->addFlash('warning', 'You can only delete your own messages.');
            return $this->redirectToRoute('app_chat_room', ['id' => $roomId]);
        }
        if ($this->isCsrfTokenValid('delete' . $message->getId(), (string) $request->request->get('_token'))) {
            $em->remove($message);
            $em->flush();
        }
        return $this->redirectToRoute('app_chat_room', ['id' => $roomId]);
    }
}
