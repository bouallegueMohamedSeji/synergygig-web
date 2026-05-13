<?php

namespace App\Controller\API;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/users')]
class ApiUserController extends AbstractController
{
    /** GET /api/users/me — current authenticated user */
    #[Route('/me', name: 'api_users_me', methods: ['GET'])]
    public function me(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['message' => 'Not authenticated.'], 401);
        }

        return $this->json($this->serializeUser($user));
    }

    /** PUT /api/users/me — update own profile */
    #[Route('/me', name: 'api_users_me_update', methods: ['PUT'])]
    public function updateMe(
        Request $request,
        EntityManagerInterface $em,
        #[CurrentUser] ?User $user
    ): JsonResponse {
        if (!$user) {
            return $this->json(['message' => 'Not authenticated.'], 401);
        }

        $data = json_decode($request->getContent(), true) ?? [];

        if (isset($data['firstName']) && $data['firstName'] !== '') {
            $user->setFirstName(trim($data['firstName']));
        }
        if (isset($data['lastName']) && $data['lastName'] !== '') {
            $user->setLastName(trim($data['lastName']));
        }

        $em->flush();

        return $this->json($this->serializeUser($user));
    }

    /** GET /api/users/{id} — public profile */
    #[Route('/{id}', name: 'api_users_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id, EntityManagerInterface $em): JsonResponse
    {
        $user = $em->getRepository(User::class)->find($id);

        if (!$user) {
            return $this->json(['message' => 'User not found.'], 404);
        }

        return $this->json($this->serializePublicUser($user));
    }

    /** GET /api/users/search?q=... — search by name or email */
    #[Route('/search', name: 'api_users_search', methods: ['GET'])]
    public function search(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $q = trim((string) $request->query->get('q', ''));

        if (strlen($q) < 2) {
            return $this->json(['message' => 'Query must be at least 2 characters.'], 400);
        }

        $qb = $em->getRepository(User::class)->createQueryBuilder('u');
        $results = $qb
            ->where('u.first_name LIKE :q OR u.last_name LIKE :q OR u.email LIKE :q')
            ->setParameter('q', '%' . addcslashes($q, '%_') . '%')
            ->setMaxResults(20)
            ->getQuery()
            ->getResult();

        return $this->json(array_map([$this, 'serializePublicUser'], $results));
    }

    /** POST /api/users/{id}/follow */
    #[Route('/{id}/follow', name: 'api_users_follow', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function follow(
        int $id,
        EntityManagerInterface $em,
        #[CurrentUser] ?User $currentUser
    ): JsonResponse {
        if (!$currentUser) {
            return $this->json(['message' => 'Not authenticated.'], 401);
        }

        $target = $em->getRepository(User::class)->find($id);
        if (!$target) {
            return $this->json(['message' => 'User not found.'], 404);
        }
        if ($target->getId() === $currentUser->getId()) {
            return $this->json(['message' => 'Cannot follow yourself.'], 400);
        }

        // Check existing follow
        $existing = $em->getRepository(\App\Entity\UserFollow::class)
            ->findOneBy(['follower' => $currentUser, 'following' => $target]);

        if ($existing) {
            return $this->json(['message' => 'Already following.'], 409);
        }

        $follow = new \App\Entity\UserFollow();
        $follow->setFollower($currentUser);
        $follow->setFollowing($target);
        $em->persist($follow);
        $em->flush();

        return $this->json(['message' => 'Followed successfully.'], 201);
    }

    /** DELETE /api/users/{id}/follow */
    #[Route('/{id}/follow', name: 'api_users_unfollow', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function unfollow(
        int $id,
        EntityManagerInterface $em,
        #[CurrentUser] ?User $currentUser
    ): JsonResponse {
        if (!$currentUser) {
            return $this->json(['message' => 'Not authenticated.'], 401);
        }

        $target = $em->getRepository(User::class)->find($id);
        if (!$target) {
            return $this->json(['message' => 'User not found.'], 404);
        }

        $follow = $em->getRepository(\App\Entity\UserFollow::class)
            ->findOneBy(['follower' => $currentUser, 'following' => $target]);

        if (!$follow) {
            return $this->json(['message' => 'Not following this user.'], 404);
        }

        $em->remove($follow);
        $em->flush();

        return $this->json(['message' => 'Unfollowed.']);
    }

    /** GET /api/users/{id}/followers */
    #[Route('/{id}/followers', name: 'api_users_followers', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function followers(int $id, EntityManagerInterface $em): JsonResponse
    {
        $user = $em->getRepository(User::class)->find($id);
        if (!$user) {
            return $this->json(['message' => 'User not found.'], 404);
        }

        $follows = $em->getRepository(\App\Entity\UserFollow::class)
            ->findBy(['following' => $user]);

        $list = array_map(fn($f) => $this->serializePublicUser($f->getFollower()), $follows);

        return $this->json($list);
    }

    /** GET /api/users/{id}/status — online presence */
    #[Route('/{id}/status', name: 'api_users_status', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function status(int $id, EntityManagerInterface $em): JsonResponse
    {
        $user = $em->getRepository(User::class)->find($id);
        if (!$user) {
            return $this->json(['message' => 'User not found.'], 404);
        }

        return $this->json([
            'status'     => $user->getOnlineStatus() ?? 'offline',
            'last_seen'  => $user->getLastSeenAt()?->format(\DateTime::ATOM),
        ]);
    }

    // ─── Serializers ───────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function serializeUser(User $u): array
    {
        return [
            'id'         => $u->getId(),
            'email'      => $u->getEmail(),
            'firstName'  => $u->getFirstName(),
            'lastName'   => $u->getLastName(),
            'role'       => $u->getRole(),
            'avatarPath' => $u->getAvatarPath(),
            'status'     => $u->getOnlineStatus() ?? 'offline',
            'lastSeen'   => $u->getLastSeenAt()?->format(\DateTime::ATOM),
            'createdAt'  => $u->getCreatedAt()?->format(\DateTime::ATOM),
        ];
    }

    /** @return array<string, mixed> */
    /** @return array<string, mixed> */
    private function serializePublicUser(?User $u): array
    {
        if (!$u) {
            return [];
        }
        return [
            'id'         => $u->getId(),
            'firstName'  => $u->getFirstName(),
            'lastName'   => $u->getLastName(),
            'role'       => $u->getRole(),
            'avatarPath' => $u->getAvatarPath(),
            'status'     => $u->getOnlineStatus() ?? 'offline',
        ];
    }
}
