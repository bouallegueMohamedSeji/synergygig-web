<?php

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Security\Authenticator\OAuth2Authenticator;
use League\OAuth2\Client\Provider\GoogleUser;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

class GoogleAuthenticator extends OAuth2Authenticator implements AuthenticationEntryPointInterface
{
    public function __construct(
        private ClientRegistry $clientRegistry,
        private EntityManagerInterface $em,
        private RouterInterface $router,
        private UserRepository $userRepository,
    ) {}

    public function supports(Request $request): ?bool
    {
        return $request->attributes->get('_route') === 'connect_google_check';
    }

    public function authenticate(Request $request): Passport
    {
        $client      = $this->clientRegistry->getClient('google');
        $accessToken = $this->fetchAccessToken($client);

        return new SelfValidatingPassport(
            new UserBadge($accessToken->getToken(), function () use ($accessToken, $client) {
                /** @var GoogleUser $googleUser */
                $googleUser = $client->fetchUserFromToken($accessToken);

                $email    = $googleUser->getEmail();
                $googleId = $googleUser->getId();

                // Try to find by google_id first, then by email
                $user = $this->userRepository->findOneBy(['google_id' => $googleId])
                    ?? $this->userRepository->findOneBy(['email' => $email]);

                if ($user) {
                    // Link account if not linked yet
                    $changed = false;
                    if (!$user->getGoogleId()) {
                        $user->setGoogleId($googleId);
                        $changed = true;
                    }
                    // Auto-verify: Google has already verified this email
                    if (!$user->isVerified()) {
                        $user->setIsVerified(true);
                        $changed = true;
                    }
                    if ($changed) {
                        $this->em->flush();
                    }
                    return $user;
                }

                // Auto-register new user
                $user = new User();
                $user->setEmail($email);
                $user->setFirstName($googleUser->getFirstName() ?? 'User');
                $user->setLastName($googleUser->getLastName() ?? '');
                $user->setGoogleId($googleId);
                $user->setRole('EMPLOYEE');
                $user->setCreatedAt(new \DateTime());
                // No password for OAuth users — set a random unusable one
                $user->setPassword(bin2hex(random_bytes(32)));
                // Google has verified this email
                $user->setIsVerified(true);

                $this->em->persist($user);
                $this->em->flush();

                return $user;
            })
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return new RedirectResponse($this->router->generate('app_dashboard'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $request->getSession()->set('oauth_error', strtr($exception->getMessageKey(), $exception->getMessageData()));
        return new RedirectResponse($this->router->generate('app_login'));
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new RedirectResponse($this->router->generate('app_login'));
    }
}
