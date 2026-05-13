<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\UserType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class ProfileController extends AbstractController
{
    #[Route('/profile', name: 'app_profile')]
    public function index(): Response
    {
        return $this->render('profile/index.html.twig', [
            'user' => $this->getUser(),
        ]);
    }

    #[Route('/profile/edit', name: 'app_profile_edit')]
    public function edit(Request $request, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Invalid authenticated user.');
        }
        $form = $this->createForm(UserType::class, $user, ['show_admin_fields' => false]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Profile updated.');
            return $this->redirectToRoute('app_profile');
        }

        return $this->render('profile/edit.html.twig', [
            'form' => $form->createView(),
            'user' => $user,
        ]);
    }

    #[Route('/profile/password', name: 'app_profile_password', methods: ['POST'])]
    public function changePassword(Request $request, UserPasswordHasherInterface $hasher, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Invalid authenticated user.');
        }
        $current = (string) $request->request->get('current_password', '');
        $newPass = (string) $request->request->get('new_password', '');
        $confirm = (string) $request->request->get('confirm_password', '');

        if (!$hasher->isPasswordValid($user, $current)) {
            $this->addFlash('error', 'Current password is incorrect.');
            return $this->redirectToRoute('app_settings');
        }
        if (strlen($newPass) < 6) {
            $this->addFlash('error', 'New password must be at least 6 characters.');
            return $this->redirectToRoute('app_settings');
        }
        if ($newPass !== $confirm) {
            $this->addFlash('error', 'Passwords do not match.');
            return $this->redirectToRoute('app_settings');
        }

        $user->setPassword($hasher->hashPassword($user, $newPass));
        $em->flush();
        $this->addFlash('success', 'Password changed successfully.');
        return $this->redirectToRoute('app_settings');
    }

    #[Route('/settings', name: 'app_settings')]
    public function settings(): Response
    {
        return $this->render('profile/settings.html.twig', [
            'user' => $this->getUser(),
        ]);
    }

    #[Route('/profile/upload-avatar', name: 'app_profile_upload_avatar', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function uploadAvatar(Request $request, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Invalid authenticated user.');
        }
        if (!$this->isCsrfTokenValid('avatar-self', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_profile');
        }

        $file = $request->files->get('avatar');
        if ($file && $file->isValid()) {
            $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            if (!in_array($file->getMimeType(), $allowedMimes)) {
                $this->addFlash('error', 'Only JPG, PNG, GIF or WEBP images are allowed.');
                return $this->redirectToRoute('app_profile');
            }
            if ($file->getSize() > 5 * 1024 * 1024) {
                $this->addFlash('error', 'Image must be smaller than 5 MB.');
                return $this->redirectToRoute('app_profile');
            }

            $projectDir = $this->getParameter('kernel.project_dir');
            if (!is_string($projectDir)) {
                throw new \RuntimeException('Invalid project directory configuration.');
            }
            $uploadDir = $projectDir . '/public/uploads/avatars';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            if ($user->getAvatar_path()) {
                $old = $uploadDir . '/' . $user->getAvatar_path();
                if (file_exists($old)) {
                    unlink($old);
                }
            }

            $filename = 'user_' . $user->getId() . '_' . uniqid() . '.' . $file->guessExtension();
            $file->move($uploadDir, $filename);
            $user->setAvatarPath($filename);
            $em->flush();
            $this->addFlash('success', 'Profile photo updated.');
        }

        return $this->redirectToRoute('app_profile');
    }

    /* ─── CV Upload ─── */
    #[Route('/profile/upload-cv', name: 'app_profile_upload_cv', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function uploadCv(Request $request, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Invalid authenticated user.');
        }
        if (!$this->isCsrfTokenValid('cv-upload', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_profile');
        }

        $file = $request->files->get('cv_file');
        if (!$file || !$file->isValid()) {
            $this->addFlash('error', 'No file selected or upload failed.');
            return $this->redirectToRoute('app_profile');
        }

        $allowedMimes = [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ];
        if (!in_array($file->getMimeType(), $allowedMimes)) {
            $this->addFlash('error', 'Only PDF, DOC or DOCX files are allowed.');
            return $this->redirectToRoute('app_profile');
        }
        if ($file->getSize() > 10 * 1024 * 1024) {
            $this->addFlash('error', 'CV file must be smaller than 10 MB.');
            return $this->redirectToRoute('app_profile');
        }

        $projectDir = $this->getParameter('kernel.project_dir');
        if (!is_string($projectDir)) {
            throw new \RuntimeException('Invalid project directory configuration.');
        }
        $uploadDir = $projectDir . '/var/uploads/cvs';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // Delete old CV if exists
        if ($user->getCvPath()) {
            $old = $uploadDir . '/' . $user->getCvPath();
            if (file_exists($old)) {
                unlink($old);
            }
        }

        $ext = $file->guessExtension() ?? 'pdf';
        $filename = 'cv_' . $user->getId() . '_' . uniqid() . '.' . $ext;
        $file->move($uploadDir, $filename);

        $user->setCvPath($filename);
        $user->setCvOriginalName($file->getClientOriginalName());
        $user->initCvUploadedAt(new \DateTime());

        // Extract plain text for skills matching (PDF only via pdftotext if available)
        $skillsText = null;
        $filePath = $uploadDir . '/' . $filename;
        if ($ext === 'pdf') {
            $out = [];
            @exec('pdftotext ' . escapeshellarg($filePath) . ' -', $out, $code);
            if ($code === 0 && !empty($out)) {
                $skillsText = implode(' ', $out);
            }
        }
        // Fallback: use the CV skills textarea if provided
        $manualSkills = trim((string) $request->request->get('cv_skills_manual', ''));
        if ($manualSkills) {
            $skillsText = $manualSkills;
        }
        if ($skillsText) {
            $user->setCvSkillsText(substr($skillsText, 0, 65535));
        }

        $em->flush();
        $this->addFlash('success', 'CV uploaded successfully.');
        return $this->redirectToRoute('app_profile');
    }

    /* ─── CV Download ─── */
    #[Route('/profile/cv/download', name: 'app_profile_cv_download')]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function downloadCv(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Invalid authenticated user.');
        }
        if (!$user->getCvPath()) {
            throw $this->createNotFoundException('No CV uploaded.');
        }

        $projectDir = $this->getParameter('kernel.project_dir');
        if (!is_string($projectDir)) {
            throw new \RuntimeException('Invalid project directory configuration.');
        }
        $filePath = $projectDir . '/var/uploads/cvs/' . $user->getCvPath();
        if (!file_exists($filePath)) {
            throw $this->createNotFoundException('CV file not found.');
        }

        $response = new BinaryFileResponse($filePath);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $user->getCvOriginalName() ?? $user->getCvPath()
        );
        return $response;
    }

    /* ─── CV Delete ─── */
    #[Route('/profile/cv/delete', name: 'app_profile_cv_delete', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function deleteCv(Request $request, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Invalid authenticated user.');
        }
        if (!$this->isCsrfTokenValid('cv-delete', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_profile');
        }

        if ($user->getCvPath()) {
            $projectDir = $this->getParameter('kernel.project_dir');
            if (!is_string($projectDir)) {
                throw new \RuntimeException('Invalid project directory configuration.');
            }
            $filePath = $projectDir . '/var/uploads/cvs/' . $user->getCvPath();
            if (file_exists($filePath)) {
                unlink($filePath);
            }
            $user->setCvPath(null);
            $user->setCvOriginalName(null);
            $user->initCvUploadedAt(null);
            $user->setCvSkillsText(null);
            $em->flush();
            $this->addFlash('success', 'CV removed.');
        }
        return $this->redirectToRoute('app_profile');
    }

    /* ─── CV Keywords API (for job scoring) ─── */
    #[Route('/api/cv/keywords', name: 'app_api_cv_keywords', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function cvKeywords(): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Invalid authenticated user.');
        }
        $text = $user->getCvSkillsText() ?? ($user->getBio() ?? '');
        return $this->json([
            'hasCV'  => $user->getCvPath() !== null,
            'name'   => $user->getCvOriginalName(),
            'text'   => $text,
        ]);
    }
}
