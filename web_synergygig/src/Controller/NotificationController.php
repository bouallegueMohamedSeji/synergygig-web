<?php

namespace App\Controller;

use App\Entity\Notification;
use App\Repository\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/notifications')]
class NotificationController extends AbstractController
{
    #[Route('/', name: 'app_notification_index')]
    public function index(NotificationRepository $repo): Response
    {
        $user = $this->getUser();
        $notifications = $repo->findBy(
            ['user' => $user],
            ['created_at' => 'DESC']
        );
        $unreadCount = $repo->count(['user' => $user, 'is_read' => false]);

        return $this->render('notification/index.html.twig', [
            'notifications' => $notifications,
            'unreadCount' => $unreadCount,
        ]);
    }

    #[Route('/{id}', name: 'app_notification_show', requirements: ['id' => '\d+'])]
    public function show(Notification $notification): Response
    {
        return $this->render('notification/show.html.twig', [
            'notification' => $notification,
        ]);
    }

    #[Route('/{id}/read', name: 'app_notification_read', methods: ['POST'])]
    public function markRead(Notification $notification, EntityManagerInterface $em): Response
    {
        $notification->setIs_read(true);
        $em->flush();
        $this->addFlash('success', 'Notification marked as read.');
        return $this->redirectToRoute('app_notification_index');
    }

    #[Route('/read-all', name: 'app_notification_read_all', methods: ['POST'])]
    public function markAllRead(Request $request, NotificationRepository $repo, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        $unread = $repo->findBy(['user' => $user, 'is_read' => false]);
        foreach ($unread as $n) {
            $n->setIs_read(true);
        }
        $em->flush();

        if ($request->isXmlHttpRequest()) {
            return new JsonResponse(['success' => true]);
        }

        $this->addFlash('success', 'All notifications marked as read.');
        return $this->redirectToRoute('app_notification_index');
    }

    #[Route('/{id}/delete', name: 'app_notification_delete', methods: ['POST'])]
    public function delete(Request $request, Notification $notification, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete' . $notification->getId(), (string) $request->request->get('_token'))) {
            $em->remove($notification);
            $em->flush();
            $this->addFlash('success', 'Notification deleted.');
        }
        return $this->redirectToRoute('app_notification_index');
    }

    #[Route('/recent', name: 'app_notification_recent')]
    public function recent(NotificationRepository $repo): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['notifications' => [], 'unread_count' => 0]);
        }
        $notifications = $repo->findBy(
            ['user' => $user],
            ['created_at' => 'DESC'],
            8
        );
        $unreadCount = $repo->count(['user' => $user, 'is_read' => false]);
        $items = [];
        foreach ($notifications as $n) {
            $items[] = [
                'id' => $n->getId(),
                'title' => $n->getTitle(),
                'body' => $n->getBody(),
                'type' => $n->getType(),
                'is_read' => $n->is_read(),
                'created_at' => $n->getCreated_at()?->format('M j, g:ia'),
            ];
        }
        return new JsonResponse(['notifications' => $items, 'unread_count' => $unreadCount]);
    }

    #[Route('/unread-count', name: 'app_notification_unread_count')]
    public function unreadCount(NotificationRepository $repo): JsonResponse
    {
        $user = $this->getUser();
        $count = $user ? $repo->count(['user' => $user, 'is_read' => false]) : 0;
        return new JsonResponse(['count' => $count]);
    }
}
