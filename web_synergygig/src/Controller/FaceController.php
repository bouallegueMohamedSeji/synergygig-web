<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/face')]
#[IsGranted('ROLE_ADMIN')]
class FaceController extends AbstractController
{
    #[Route('/enroll', name: 'app_face_enroll', methods: ['GET'])]
    public function enroll(): Response
    {
        return $this->render('face/enroll.html.twig');
    }

    #[Route('/enroll', name: 'app_face_enroll_process', methods: ['POST'])]
    public function enrollProcess(
        Request $request,
        EntityManagerInterface $em
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        $imageData = (string) $request->request->get('face_image', '');
        $decoded = null;

        if (!empty($imageData)) {
            // Decode base64 image data (strip data:image/...;base64, prefix)
            if (str_contains($imageData, ',')) {
                $imageData = explode(',', $imageData, 2)[1];
            }
            $decoded = base64_decode($imageData, true);
            if ($decoded === false) {
                $this->addFlash('danger', 'Invalid captured image data.');
                return $this->redirectToRoute('app_face_enroll');
            }
        } else {
            /** @var UploadedFile|null $uploaded */
            $uploaded = $request->files->get('face_upload');
            if ($uploaded instanceof UploadedFile && $uploaded->isValid()) {
                $mimeType = (string) $uploaded->getMimeType();
                if (!str_starts_with($mimeType, 'image/')) {
                    $this->addFlash('danger', 'Uploaded file must be an image.');
                    return $this->redirectToRoute('app_face_enroll');
                }

                $decoded = @file_get_contents($uploaded->getPathname());
                if ($decoded === false || $decoded === '') {
                    $this->addFlash('danger', 'Could not read uploaded image.');
                    return $this->redirectToRoute('app_face_enroll');
                }
            }
        }

        if ($decoded === null) {
            $this->addFlash('warning', 'No image captured or uploaded.');
            return $this->redirectToRoute('app_face_enroll');
        }

        // Save temp image
        $tmpDir = sys_get_temp_dir();
        $tmpFile = $tmpDir . DIRECTORY_SEPARATOR . 'face_' . uniqid() . '.png';
        file_put_contents($tmpFile, $decoded);

        try {
            // Run Python encoding script
            $projectDir = $this->getParameter('kernel.project_dir');
            if (!is_string($projectDir)) {
                throw new \RuntimeException('Invalid project directory configuration.');
            }
            $pythonScript = $projectDir . DIRECTORY_SEPARATOR . 'python' . DIRECTORY_SEPARATOR . 'face_encode_image.py';
            $cmd = sprintf('python3 "%s" "%s" 2>&1', str_replace('/', DIRECTORY_SEPARATOR, $pythonScript), $tmpFile);
            $output = shell_exec($cmd);
            $outputText = (string) $output;

            if ($output === null || trim($outputText) === '') {
                $this->addFlash('danger', 'Face recognition service unavailable. Make sure Python and required packages (mediapipe, opencv-python) are installed.');
                return $this->redirectToRoute('app_face_enroll');
            }

            // Find JSON in output (skip any warnings/logs before it)
            $jsonStart = strpos($outputText, '{');
            if ($jsonStart === false) {
                $this->addFlash('danger', 'Face recognition returned unexpected output: ' . substr($outputText, 0, 200));
                return $this->redirectToRoute('app_face_enroll');
            }

            $result = json_decode(substr($outputText, $jsonStart), true);
            if (!$result || !isset($result['success'])) {
                $this->addFlash('danger', 'Invalid response from face recognition service.');
                return $this->redirectToRoute('app_face_enroll');
            }

            if (!$result['success']) {
                $this->addFlash('warning', $result['error'] ?? 'Face encoding failed.');
                return $this->redirectToRoute('app_face_enroll');
            }

            // Store encoding in user (encoding is a JSON string of floats)
            $encoding = $result['encoding'];
            if (is_array($encoding)) {
                $encoding = json_encode($encoding);
            }
            $user->setFaceEncoding($encoding);
            $em->flush();

            $this->addFlash('success', 'Face enrolled successfully!');
            return $this->redirectToRoute('app_profile');
        } finally {
            // Clean up temp file
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }

    #[Route('/remove', name: 'app_face_remove', methods: ['POST'])]
    public function remove(
        Request $request,
        EntityManagerInterface $em
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        if ($this->isCsrfTokenValid('face_remove', (string) $request->request->get('_token'))) {
            $user->setFaceEncoding(null);
            $em->flush();
            $this->addFlash('success', 'Face ID removed.');
        }

        return $this->redirectToRoute('app_profile');
    }
}
