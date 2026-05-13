<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrationType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class AuthController extends AbstractController
{
    private const FACE_LOGIN_THRESHOLD = 0.11;
    private const FACE_SINGLE_USER_THRESHOLD = 0.08;
    private const FACE_RATIO_MARGIN = 0.50;
    private const FACE_PYTHON_TIMEOUT_SECONDS = 15;

    #[Route('/login', name: 'app_login')]
    public function login(AuthenticationUtils $authUtils): Response
    {
        return $this->render('auth/login.html.twig', [
            'last_username' => $authUtils->getLastUsername(),
            'error' => $authUtils->getLastAuthenticationError(),
        ]);
    }

    #[Route('/login/face', name: 'app_login_face', methods: ['POST'])]
    public function loginWithFace(
        Request $request,
        EntityManagerInterface $em,
        Security $security
    ): JsonResponse {
        @set_time_limit(20);

        $imageData = (string) $request->request->get('face_image', '');
        $email = strtolower(trim((string) $request->request->get('email', '')));

        if ($imageData === '') {
            return $this->json([
                'success' => false,
                'message' => 'No face image captured.',
            ], 400);
        }

        if (str_contains($imageData, ',')) {
            $imageData = explode(',', $imageData, 2)[1];
        }

        $decoded = base64_decode($imageData, true);
        if ($decoded === false) {
            return $this->json([
                'success' => false,
                'message' => 'Invalid face image data.',
            ], 400);
        }

        $tmpFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'face_login_' . uniqid('', true) . '.png';
        file_put_contents($tmpFile, $decoded);

        try {
            $capturedEncoding = $this->extractFaceEncodingFromImage($tmpFile);
            if ($capturedEncoding === null) {
                return $this->json([
                    'success' => false,
                    'message' => 'No face detected. Try again with better lighting and a centered face.',
                ], 422);
            }

            $bestUser = null;
            $bestDistance = 1.0;

            if ($email !== '') {
                $targetUser = $em->getRepository(User::class)->findOneBy(['email' => $email]);
                if (!$targetUser || !$targetUser->isActive()) {
                    return $this->json([
                        'success' => false,
                        'message' => 'Account not found for this email.',
                    ], 401);
                }

                $storedRaw = $targetUser->getFaceEncoding();
                if (!$storedRaw) {
                    return $this->json([
                        'success' => false,
                        'message' => 'No Face ID enrolled for this account. Please enroll first.',
                    ], 409);
                }

                $stored = json_decode($storedRaw, true);
                if (!is_array($stored) || $stored === []) {
                    return $this->json([
                        'success' => false,
                        'message' => 'Stored Face ID data is invalid. Please re-enroll.',
                    ], 409);
                }

                $distance = $this->cosineDistance($capturedEncoding, $stored);
                if ($distance === null || $distance > self::FACE_SINGLE_USER_THRESHOLD) {
                    return $this->json([
                        'success' => false,
                        'message' => 'Face did not match this email account. Try again or use password login.',
                    ], 401);
                }

                $bestUser = $targetUser;
                $bestDistance = $distance;
            } else {
                $users = $em->createQueryBuilder()
                    ->select('u')
                    ->from(User::class, 'u')
                    ->where('u.face_encoding IS NOT NULL')
                    ->andWhere('u.is_active = :active')
                    ->setParameter('active', true)
                    ->getQuery()
                    ->getResult();

                $allDistances = [];

                /** @var User $candidate */
                foreach ($users as $candidate) {
                    $storedRaw = $candidate->getFaceEncoding();
                    if (!$storedRaw) {
                        continue;
                    }

                    $stored = json_decode($storedRaw, true);
                    if (!is_array($stored) || $stored === []) {
                        continue;
                    }

                    $distance = $this->cosineDistance($capturedEncoding, $stored);
                    if ($distance === null) {
                        continue;
                    }

                    $allDistances[] = $distance;

                    if ($distance < $bestDistance) {
                        $bestDistance = $distance;
                        $bestUser = $candidate;
                    }
                }

                $effectiveThreshold = count($allDistances) === 1
                    ? self::FACE_SINGLE_USER_THRESHOLD
                    : self::FACE_LOGIN_THRESHOLD;

                if (!$bestUser || $bestDistance > $effectiveThreshold) {
                    return $this->json([
                        'success' => false,
                        'message' => 'Face not recognized. Use email/password or re-enroll Face ID.',
                    ], 401);
                }

                // Ratio test: best must be significantly better than second best
                if (count($allDistances) >= 2) {
                    sort($allDistances);
                    $secondBest = $allDistances[1];
                    if ($secondBest > 1e-9 && ($bestDistance / $secondBest) > self::FACE_RATIO_MARGIN) {
                        return $this->json([
                            'success' => false,
                            'message' => 'Face match is ambiguous. Please enter your email and try again.',
                        ], 401);
                    }
                }
            }

            if (!$bestUser->isVerified()) {
                return $this->json([
                    'success' => false,
                    'message' => 'Your email address is not verified. Please check your inbox and click the verification link.',
                ], 403);
            }

            $response = $security->login($bestUser, null, 'main');
            if ($response instanceof Response) {
                return $this->json([
                    'success' => true,
                    'message' => 'Face recognized. Signing you in...',
                    'redirect' => $response->headers->get('Location') ?: $this->generateUrl('app_dashboard'),
                ]);
            }

            return $this->json([
                'success' => true,
                'message' => 'Face recognized. Signing you in...',
                'redirect' => $this->generateUrl('app_dashboard'),
            ]);
        } catch (\Throwable $e) {
            return $this->json([
                'success' => false,
                'message' => 'Face login failed: ' . $e->getMessage(),
            ], 500);
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }

    #[Route('/signup', name: 'app_signup', methods: ['GET', 'POST'])]
    public function signup(
        Request $request,
        UserPasswordHasherInterface $hasher,
        EntityManagerInterface $em,
    ): Response {
        $form = $this->createForm(RegistrationType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();

            $existing = $em->getRepository(User::class)->findOneBy(['email' => $data['email']]);
            if ($existing) {
                $this->addFlash('error', 'An account with this email already exists.');
                return $this->redirectToRoute('app_signup');
            }

            $user = new User();
            $user->setEmail($data['email']);
            $user->setFirstName($data['first_name']);
            $user->setLastName($data['last_name']);
            $user->setRole($data['role']);
            $user->setIsActive(true);
            $user->setIsVerified(false);
            $user->setIsOnline(false);
            $user->setPassword($hasher->hashPassword($user, $data['password']));

            // Generate email verification token
            $token = bin2hex(random_bytes(32));
            $user->setResetToken($token);
            $user->initResetTokenExpiresAt(new \DateTime('+24 hours'));

            $em->persist($user);
            $em->flush();

            // Send verification email via Mailjet HTTP API
            $this->sendVerificationEmail($user, $token, $request);

            $request->getSession()->set('verify_email_address', $user->getEmail());
            $this->addFlash('success', 'Account created! Please check your email to verify your account.');
            return $this->redirectToRoute('app_check_email');
        }

        return $this->render('auth/signup.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/check-email', name: 'app_check_email')]
    public function checkEmail(Request $request): Response
    {
        $email = $request->getSession()->get('verify_email_address', '');
        if ($email === '') {
            return $this->redirectToRoute('app_signup');
        }

        return $this->render('auth/check_email.html.twig', [
            'email' => $email,
        ]);
    }

    #[Route('/verify-email/{token}', name: 'app_verify_email', methods: ['GET'])]
    public function verifyEmail(
        string $token,
        EntityManagerInterface $em,
    ): Response {
        $user = $em->getRepository(User::class)->findOneBy(['reset_token' => $token]);

        if (!$user) {
            $this->addFlash('error', 'Invalid verification link.');
            return $this->redirectToRoute('app_signup');
        }

        if ($user->getResetTokenExpiresAt() < new \DateTime()) {
            $this->addFlash('error', 'Verification link has expired. Please sign up again.');
            return $this->redirectToRoute('app_signup');
        }

        $user->setIsVerified(true);
        $user->setResetToken(null);
        $user->initResetTokenExpiresAt(null);
        $em->flush();

        $this->addFlash('success', 'Email verified! You can now sign in.');
        return $this->redirectToRoute('app_login');
    }

    #[Route('/resend-verification', name: 'app_resend_verification', methods: ['POST'])]
    public function resendVerification(
        Request $request,
        EntityManagerInterface $em,
    ): Response {
        $email = trim((string) $request->request->get('email', ''));
        if ($email === '') {
            $email = $request->getSession()->get('verify_email_address', '');
        }

        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (!$user || $user->isVerified()) {
            $this->addFlash('error', 'Invalid request.');
            return $this->redirectToRoute('app_login');
        }

        $token = bin2hex(random_bytes(32));
        $user->setResetToken($token);
        $user->initResetTokenExpiresAt(new \DateTime('+24 hours'));
        $em->flush();

        $this->sendVerificationEmail($user, $token, $request);

        $request->getSession()->set('verify_email_address', $email);
        $this->addFlash('success', 'Verification email resent!');
        return $this->redirectToRoute('app_check_email');
    }

    private function sendVerificationEmail(User $user, string $token, Request $request): void
    {
        $verifyUrl = $request->getSchemeAndHttpHost() . $this->generateUrl('app_verify_email', ['token' => $token]);

        $htmlBody =
            '<div style="font-family:Arial,sans-serif;max-width:480px;margin:0 auto;padding:32px;background:#0f172a;color:#e2e8f0;border-radius:12px">' .
            '<h2 style="text-align:center;color:#8b5cf6;margin:0 0 8px">SynergyGig</h2>' .
            '<p style="text-align:center;color:#94a3b8;font-size:14px;margin:0 0 24px">Email Verification</p>' .
            '<p style="color:#e2e8f0;font-size:15px">Hi <strong style="color:#3b82f6">' . htmlspecialchars((string) $user->getFirstName()) . '</strong>,</p>' .
            '<p style="color:#94a3b8;font-size:14px">Welcome to SynergyGig! Please verify your email to activate your account.</p>' .
            '<div style="text-align:center;margin:24px 0">' .
            '<a href="' . htmlspecialchars($verifyUrl) . '" style="display:inline-block;padding:14px 32px;background:linear-gradient(135deg,#0d9488,#0f766e);color:#fff;font-weight:700;font-size:15px;text-decoration:none;border-radius:8px">Verify My Email →</a>' .
            '</div>' .
            '<div style="padding:12px 16px;background:rgba(139,92,246,0.08);border-radius:8px;margin:0 0 16px">' .
            '<p style="color:#94a3b8;font-size:13px;margin:0">If you didn\'t create an account, you can safely ignore this email.</p>' .
            '</div>' .
            '<p style="text-align:center;color:#64748b;font-size:12px;margin:16px 0 0">© 2026 SynergyGig · Secure HR Platform</p>' .
            '</div>';

        $textBody = "Hi {$user->getFirstName()},\n\nWelcome to SynergyGig! Please verify your email to activate your account.\n\nVerify here: {$verifyUrl}\n\nIf you didn't create an account, you can safely ignore this email.\n\n© 2026 SynergyGig";

        $this->sendEmailViaMailjet((string) $user->getEmail(), 'SynergyGig — Verify Your Email', $htmlBody, $textBody);
    }

    /**
     * Send email via Mailjet HTTP API v3.1 — uses HTTPS (port 443), no SMTP needed.
     */
    private function sendEmailViaMailjet(string $toEmail, string $subject, string $htmlBody, string $textBody = ''): bool
    {
        $apiKey = $_ENV['MAILJET_API_KEY'] ?? '';
        $secretKey = $_ENV['MAILJET_SECRET_KEY'] ?? '';
        $fromEmail = $_ENV['MAILER_FROM'] ?? 'noreply@synergygig.work.gd';
        $replyTo = $_ENV['MAILER_REPLY_TO'] ?? 'synergygig@gmail.com';

        if ($apiKey === '' || $secretKey === '') {
            return false;
        }

        if ($textBody === '') {
            $textBody = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>'], "\n", $htmlBody));
            $textBody = html_entity_decode($textBody, ENT_QUOTES, 'UTF-8');
            $textBody = preg_replace('/\n{3,}/', "\n\n", trim($textBody));
        }

        $message = [
            'From' => ['Email' => $fromEmail, 'Name' => 'SynergyGig HR Platform'],
            'To' => [['Email' => $toEmail]],
            'ReplyTo' => ['Email' => $replyTo, 'Name' => 'SynergyGig Support'],
            'Subject' => $subject,
            'HTMLPart' => $htmlBody,
            'TextPart' => $textBody,
            'CustomID' => 'synergygig-' . bin2hex(random_bytes(8)),
        ];

        $payload = json_encode(['Messages' => [$message]]);

        $ch = curl_init('https://api.mailjet.com/v3.1/send');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
            ],
            CURLOPT_USERPWD => $apiKey . ':' . $secretKey,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode >= 200 && $httpCode < 300;
    }

    #[Route('/forgot-password', name: 'app_forgot_password', methods: ['GET', 'POST'])]
    public function forgotPassword(
        Request $request,
        EntityManagerInterface $em,
    ): Response {
        if ($request->isMethod('POST')) {
            $emailAddr = trim((string) $request->request->get('email', ''));
            if ($emailAddr === '') {
                $this->addFlash('error', 'Please enter your email address.');
                return $this->redirectToRoute('app_forgot_password');
            }

            $user = $em->getRepository(User::class)->findOneBy(['email' => $emailAddr]);
            if (!$user) {
                $this->addFlash('error', 'No account found with this email address.');
                return $this->redirectToRoute('app_forgot_password');
            }

            // Generate 6-digit OTP
            $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $user->setResetToken(password_hash($otp, PASSWORD_BCRYPT));
            $user->initResetTokenExpiresAt(new \DateTime('+10 minutes'));
            $em->flush();

            // Store email in session for the verify step
            $request->getSession()->set('reset_email', $emailAddr);

            // Send OTP via Mailjet HTTP API (HTTPS, no SMTP)
            $htmlBody =
                '<div style="font-family:Arial,sans-serif;max-width:480px;margin:0 auto;padding:32px;background:#0f172a;color:#e2e8f0;border-radius:12px">' .
                '<h2 style="text-align:center;color:#8b5cf6;margin:0 0 8px">SynergyGig</h2>' .
                '<p style="text-align:center;color:#94a3b8;font-size:14px;margin:0 0 24px">Password Reset Code</p>' .
                '<div style="text-align:center;padding:20px;background:rgba(139,92,246,0.1);border-radius:8px;margin:0 0 24px">' .
                '<span style="font-size:32px;font-weight:800;letter-spacing:8px;color:#3b82f6">' . $otp . '</span>' .
                '</div>' .
                '<p style="text-align:center;color:#94a3b8;font-size:13px;margin:0">This code expires in 10 minutes.<br>If you did not request this, ignore this email.</p>' .
                '</div>';

            $textBody = "SynergyGig - Password Reset\n\nYour password reset code is: {$otp}\n\nThis code expires in 10 minutes.\nIf you did not request this, ignore this email.\n\n© 2026 SynergyGig";

            $this->sendEmailViaMailjet($emailAddr, 'SynergyGig - Password Reset Code', $htmlBody, $textBody);

            return $this->redirectToRoute('app_verify_otp');
        }

        return $this->render('auth/forgot_password.html.twig');
    }

    #[Route('/verify-otp', name: 'app_verify_otp', methods: ['GET', 'POST'])]
    public function verifyOtp(
        Request $request,
        EntityManagerInterface $em,
    ): Response {
        $email = $request->getSession()->get('reset_email', '');
        if ($email === '') {
            return $this->redirectToRoute('app_forgot_password');
        }

        if ($request->isMethod('POST')) {
            $otp = trim((string) $request->request->get('otp', ''));
            if (strlen($otp) !== 6) {
                $this->addFlash('error', 'Please enter the 6-digit code.');
                return $this->render('auth/verify_otp.html.twig', ['email' => $email]);
            }

            $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
            if (!$user || !$user->getResetToken() || !$user->getResetTokenExpiresAt()) {
                $this->addFlash('error', 'Invalid or expired reset request. Please try again.');
                return $this->redirectToRoute('app_forgot_password');
            }

            if ($user->getResetTokenExpiresAt() < new \DateTime()) {
                $user->setResetToken(null);
                $user->initResetTokenExpiresAt(null);
                $em->flush();
                $this->addFlash('error', 'The code has expired. Please request a new one.');
                return $this->redirectToRoute('app_forgot_password');
            }

            if (!password_verify($otp, $user->getResetToken())) {
                $this->addFlash('error', 'Invalid code. Please try again.');
                return $this->render('auth/verify_otp.html.twig', ['email' => $email]);
            }

            // OTP verified — allow password reset
            $request->getSession()->set('reset_verified', true);
            return $this->redirectToRoute('app_reset_password');
        }

        return $this->render('auth/verify_otp.html.twig', [
            'email' => $email,
        ]);
    }

    #[Route('/reset-password', name: 'app_reset_password', methods: ['GET', 'POST'])]
    public function resetPassword(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
    ): Response {
        $email = $request->getSession()->get('reset_email', '');
        $verified = $request->getSession()->get('reset_verified', false);
        if ($email === '' || !$verified) {
            return $this->redirectToRoute('app_forgot_password');
        }

        if ($request->isMethod('POST')) {
            $password = (string) $request->request->get('password', '');
            $confirm = (string) $request->request->get('password_confirm', '');

            if (strlen($password) < 8) {
                $this->addFlash('error', 'Password must be at least 8 characters.');
                return $this->render('auth/reset_password.html.twig');
            }
            if ($password !== $confirm) {
                $this->addFlash('error', 'Passwords do not match.');
                return $this->render('auth/reset_password.html.twig');
            }

            $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
            if (!$user) {
                return $this->redirectToRoute('app_forgot_password');
            }

            $user->setPassword($hasher->hashPassword($user, $password));
            $user->setResetToken(null);
            $user->initResetTokenExpiresAt(null);
            $em->flush();

            // Cleanup session
            $request->getSession()->remove('reset_email');
            $request->getSession()->remove('reset_verified');

            $this->addFlash('success', 'Password reset successfully! You can now sign in.');
            return $this->redirectToRoute('app_login');
        }

        return $this->render('auth/reset_password.html.twig');
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(): never
    {
        // Symfony's security system intercepts this route
        throw new \LogicException('This should never be reached.');
    }

    /** @return array<int, mixed>|null */
    private function extractFaceEncodingFromImage(string $imagePath): ?array
    {
        $projectDir = $this->getParameter('kernel.project_dir');
        if (!is_string($projectDir)) {
            throw new \RuntimeException('Invalid project directory configuration.');
        }
        $pythonScript = $projectDir . DIRECTORY_SEPARATOR . 'python' . DIRECTORY_SEPARATOR . 'face_encode_image.py';
        $cmd = sprintf('/usr/bin/python3 "%s" "%s"', $pythonScript, $imagePath);
        $output = $this->runProcessWithTimeout($cmd, self::FACE_PYTHON_TIMEOUT_SECONDS);

        if (trim($output) === '') {
            throw new \RuntimeException('Face recognition service unavailable.');
        }

        $jsonStart = strpos($output, '{');
        if ($jsonStart === false) {
            throw new \RuntimeException('Unexpected face recognition output.');
        }

        $result = json_decode((string) substr($output, $jsonStart), true);
        if (!is_array($result) || !array_key_exists('success', $result)) {
            throw new \RuntimeException('Invalid face recognition response.');
        }

        if (!$result['success']) {
            $error = strtolower((string) ($result['error'] ?? ''));
            if (str_contains($error, 'no face') || str_contains($error, 'multiple faces')) {
                return null;
            }
            throw new \RuntimeException((string) ($result['error'] ?? 'Face encoding failed.'));
        }

        $encoding = $result['encoding'] ?? null;
        if (is_string($encoding)) {
            $encoding = json_decode($encoding, true);
        }

        if (!is_array($encoding) || $encoding === []) {
            throw new \RuntimeException('Invalid encoding vector.');
        }

        return $encoding;
    }

    /**
     * @param array<int, float|int|string> $a
     * @param array<int, float|int|string> $b
     */
    private function cosineDistance(array $a, array $b): ?float
    {
        $len = min(count($a), count($b));
        if ($len === 0) {
            return null;
        }

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 0; $i < $len; $i++) {
            $x = (float) $a[$i];
            $y = (float) $b[$i];
            $dot += $x * $y;
            $normA += $x * $x;
            $normB += $y * $y;
        }

        if ($normA <= 0.0 || $normB <= 0.0) {
            return null;
        }

        $cosine = $dot / (sqrt($normA) * sqrt($normB));
        $cosine = max(-1.0, min(1.0, $cosine));

        return 1.0 - $cosine;
    }

    private function runProcessWithTimeout(string $command, int $timeoutSeconds): string
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to start face recognition process.');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $start = microtime(true);

        while (true) {
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);

            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }

            if ((microtime(true) - $start) > $timeoutSeconds) {
                proc_terminate($process);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                throw new \RuntimeException('Face verification timed out. Please retry with better lighting.');
            }

            usleep(100000);
        }

        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $out = trim($stdout);
        if ($out === '') {
            throw new \RuntimeException('Face recognition service unavailable.');
        }

        return $out;
    }
}
