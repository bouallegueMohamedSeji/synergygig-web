<?php

namespace App\Controller\API;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/auth')]
class ApiAuthController extends AbstractController
{
    public function __construct(
        private RateLimiterFactory $loginAttemptLimiter
    ) {}

    #[Route('/login', name: 'api_login', methods: ['POST'])]
    public function login(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
        JWTTokenManagerInterface $jwt
    ): JsonResponse {
        $limiter = $this->loginAttemptLimiter->create($request->getClientIp());
        if (!$limiter->consume(1)->isAccepted()) {
            return $this->json(['message' => 'Too many login attempts. Try again in 15 minutes.'], 429);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $email    = strtolower(trim((string) ($data['email'] ?? '')));
        $password = (string) ($data['password'] ?? '');

        if ($email === '' || $password === '') {
            return $this->json(['message' => 'email and password are required.'], 400);
        }

        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);

        if (!$user || !$hasher->isPasswordValid($user, $password)) {
            return $this->json(['message' => 'Invalid credentials.'], 401);
        }

        // Reset limiter on success
        $limiter->reset();

        $token = $jwt->create($user);

        return $this->json([
            'token' => $token,
            'user'  => [
                'id'         => $user->getId(),
                'email'      => $user->getEmail(),
                'firstName'  => $user->getFirstName(),
                'lastName'   => $user->getLastName(),
                'role'       => $user->getRole(),
                'avatarPath' => $user->getAvatarPath(),
            ],
        ]);
    }

    #[Route('/register', name: 'api_register_v2', methods: ['POST'])]
    public function register(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
        JWTTokenManagerInterface $jwt
    ): JsonResponse {
        $data = json_decode($request->getContent(), true) ?? [];

        $email     = strtolower(trim((string) ($data['email'] ?? '')));
        $password  = (string) ($data['password'] ?? '');
        $firstName = trim((string) ($data['firstName'] ?? ''));
        $lastName  = trim((string) ($data['lastName'] ?? ''));

        if (!$email || !$password || !$firstName || !$lastName) {
            return $this->json(['message' => 'email, password, firstName and lastName are required.'], 400);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['message' => 'Invalid email format.'], 400);
        }

        if (strlen($password) < 8) {
            return $this->json(['message' => 'Password must be at least 8 characters.'], 400);
        }

        if ($em->getRepository(User::class)->findOneBy(['email' => $email])) {
            return $this->json(['message' => 'Email already registered.'], 409);
        }

        $user = new User();
        $user->setEmail($email);
        $user->setFirstName($firstName);
        $user->setLastName($lastName);
        $user->setRole('EMPLOYEE');
        $user->setCreatedAt(new \DateTime());
        $user->setPassword($hasher->hashPassword($user, $password));

        $em->persist($user);
        $em->flush();

        $token = $jwt->create($user);

        return $this->json([
            'token' => $token,
            'user'  => [
                'id'        => $user->getId(),
                'email'     => $user->getEmail(),
                'firstName' => $user->getFirstName(),
                'lastName'  => $user->getLastName(),
                'role'      => $user->getRole(),
            ],
        ], 201);
    }
}
