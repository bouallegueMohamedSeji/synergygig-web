<?php

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Security\Authenticator\OAuth2Authenticator;
use League\OAuth2\Client\Provider\GithubResourceOwner;
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

class GithubAuthenticator extends OAuth2Authenticator implements AuthenticationEntryPointInterface
{
    public function __construct(
        private ClientRegistry $clientRegistry,
        private EntityManagerInterface $em,
        private RouterInterface $router,
        private UserRepository $userRepository,
    ) {}

    public function supports(Request $request): ?bool
    {
        return $request->attributes->get('_route') === 'connect_github_check';
    }

    public function authenticate(Request $request): Passport
    {
        $client      = $this->clientRegistry->getClient('github');
        $accessToken = $this->fetchAccessToken($client);

        return new SelfValidatingPassport(
            new UserBadge($accessToken->getToken(), function () use ($accessToken, $client) {
                /** @var GithubResourceOwner $githubUser */
                $githubUser = $client->fetchUserFromToken($accessToken);

                $githubId = (string) $githubUser->getId();
                $email    = $githubUser->getEmail();

                // Try to find by github_id first, then by email (email may be null on GitHub)
                $user = $this->userRepository->findOneBy(['github_id' => $githubId]);

                if (!$user && $email) {
                    $user = $this->userRepository->findOneBy(['email' => $email]);
                }

                if ($user) {
                    $changed = false;
                    if (!$user->getGithubId()) {
                        $user->setGithubId($githubId);
                        $changed = true;
                    }
                    // Auto-verify: GitHub has already verified this account
                    if (!$user->isVerified()) {
                        $user->setIsVerified(true);
                        $changed = true;
                    }
                    if ($changed) {
                        $this->em->flush();
                    }
                    return $user;
                }

                // GitHub email can be null if user set it private — require email scope
                if (!$email) {
                    throw new AuthenticationException('Your GitHub account has no public email. Please add a public email to your GitHub profile and try again.');
                }

                // Auto-register
                $nameParts = explode(' ', (string) $githubUser->getName(), 2);
                $user = new User();
                $user->setEmail($email);
                $user->setFirstName($nameParts[0] ?? $githubUser->getNickname() ?? 'User');
                $user->setLastName($nameParts[1] ?? '');
                $user->setGithubId($githubId);
                $user->setRole('EMPLOYEE');
                $user->setCreatedAt(new \DateTime());
                $user->setPassword(bin2hex(random_bytes(32)));
                // GitHub has already verified this account
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
