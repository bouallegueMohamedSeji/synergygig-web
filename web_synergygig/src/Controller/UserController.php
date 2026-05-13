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
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Knp\Component\Pager\PaginatorInterface;

#[Route('/users')]
#[IsGranted('ROLE_HR')]
class UserController extends AbstractController
{
    #[Route('/', name: 'app_user_index')]
    public function index(Request $request, UserRepository $repo, PaginatorInterface $paginator): Response
    {
        $qb = $repo->createQueryBuilder('u')->orderBy('u.id', 'DESC');

        $role = $request->query->get('role');
        if ($role) {
            $qb->andWhere('u.role = :role')->setParameter('role', $role);
        }

        $q = $request->query->get('q');
        if ($q) {
            $qb->andWhere('LOWER(CONCAT(u.first_name, \' \', u.last_name)) LIKE :q OR LOWER(u.email) LIKE :q')
               ->setParameter('q', '%' . mb_strtolower((string) $q) . '%');
        }

        $pagination = $paginator->paginate($qb, $request->query->getInt('page', 1), 12);

        return $this->render('user/index.html.twig', [
            'users' => $pagination,
            'pagination' => $pagination,
        ]);
    }

    #[Route('/new', name: 'app_user_new')]
    public function new(Request $request, EntityManagerInterface $em, UserPasswordHasherInterface $hasher): Response
    {
        $user = new User();
        $user->setIs_active(true);

        $form = $this->createForm(UserType::class, $user, ['is_new' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Hash the password using Symfony's hasher
            $plainPassword = $form->get('password')->getData();
            $user->setPassword($hasher->hashPassword($user, $plainPassword));

            $em->persist($user);
            $em->flush();
            $this->addFlash('success', 'User created successfully.');
            return $this->redirectToRoute('app_user_index');
        }

        return $this->render('user/form.html.twig', [
            'form' => $form->createView(),
            'user' => $user,
            'is_edit' => false,
        ]);
    }

    #[Route('/{id}', name: 'app_user_show', requirements: ['id' => '\d+'])]
    public function show(User $user): Response
    {
        return $this->render('user/show.html.twig', [
            'user' => $user,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_user_edit', requirements: ['id' => '\d+'])]
    public function edit(Request $request, User $user, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(UserType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'User updated successfully.');
            return $this->redirectToRoute('app_user_index');
        }

        return $this->render('user/form.html.twig', [
            'form' => $form->createView(),
            'user' => $user,
            'is_edit' => true,
        ]);
    }

    #[Route('/{id}/toggle-freeze', name: 'app_user_toggle_freeze', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function toggleFreeze(Request $request, User $user, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('freeze-' . $user->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_user_show', ['id' => $user->getId()]);
        }

        $newState = !$user->isActive();
        $user->setIsActive($newState);
        $em->flush();

        $this->addFlash('success', sprintf(
            'User %s %s has been %s.',
            $user->getFirstName(), $user->getLastName(),
            $newState ? 'unfrozen (reactivated)' : 'frozen (deactivated)'
        ));
        return $this->redirectToRoute('app_user_show', ['id' => $user->getId()]);
    }

    #[Route('/{id}/delete', name: 'app_user_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, User $user, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete-' . $user->getId(), (string) $request->request->get('_token'))) {
            $em->remove($user);
            $em->flush();
            $this->addFlash('success', 'User permanently deleted.');
        }

        return $this->redirectToRoute('app_user_index');
    }

    #[Route('/{id}/upload-avatar', name: 'app_user_upload_avatar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function uploadAvatar(Request $request, User $user, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('avatar-' . $user->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_user_edit', ['id' => $user->getId()]);
        }

        $file = $request->files->get('avatar');
        if ($file && $file->isValid()) {
            $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            if (!in_array($file->getMimeType(), $allowedMimes)) {
                $this->addFlash('error', 'Only JPG, PNG, GIF or WEBP images are allowed.');
                return $this->redirectToRoute('app_user_edit', ['id' => $user->getId()]);
            }
            if ($file->getSize() > 5 * 1024 * 1024) {
                $this->addFlash('error', 'Image must be smaller than 5 MB.');
                return $this->redirectToRoute('app_user_edit', ['id' => $user->getId()]);
            }

            /** @var string $projectDir */
            $projectDir = $this->getParameter('kernel.project_dir');
            $uploadDir = $projectDir . '/public/uploads/avatars';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            if ($user->getAvatar_path()) {
                $old = $uploadDir . '/' . (string) $user->getAvatar_path();
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

        return $this->redirectToRoute('app_user_edit', ['id' => $user->getId()]);
    }
}
