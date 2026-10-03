<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Repository\NotificationRepository;
use App\Util\Pagination;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/notifications')]
#[IsGranted(User::ROLE_ADMIN)]
class NotificationController extends AbstractController
{
    #[Route('', name: 'admin_notification_index', methods: ['GET'])]
    public function index(Request $request, NotificationRepository $notifications): Response
    {
        $qb = $notifications->createQueryBuilder('n')->leftJoin('n.colis', 'c')->addSelect('c')->orderBy('n.createdAt', 'DESC');
        if ('echecs' === $request->query->get('filtre')) {
            $qb->andWhere('n.succes = false');
        }

        return $this->render('admin/notification/index.html.twig', [
            'notifications' => Pagination::depuisRequete($qb, $request, 40),
            'filtre' => $request->query->get('filtre'),
        ]);
    }
}
