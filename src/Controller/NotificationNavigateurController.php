<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\NotificationPush;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Abonnement du navigateur aux notifications (appelé par public/js/sotrama.js). */
#[Route('/notifications')]
class NotificationNavigateurController extends AbstractController
{
    public function __construct(private readonly NotificationPush $push)
    {
    }

    #[Route('/abonnement', name: 'push_abonner', methods: ['POST'])]
    public function abonner(Request $request): JsonResponse
    {
        if ($erreur = $this->verifier($request)) {
            return $erreur;
        }
        $data = $request->toArray();
        $endpoint = (string) ($data['endpoint'] ?? '');
        $p256dh = (string) ($data['keys']['p256dh'] ?? '');
        $auth = (string) ($data['keys']['auth'] ?? '');
        if (!str_starts_with($endpoint, 'https://') || '' === $p256dh || '' === $auth) {
            return $this->json(['error' => 'Abonnement invalide.'], 400);
        }
        /** @var User $user */
        $user = $this->getUser();
        $this->push->abonner($user, $endpoint, $p256dh, $auth, $request->headers->get('User-Agent'));

        return $this->json(['ok' => true]);
    }

    #[Route('/desabonnement', name: 'push_desabonner', methods: ['POST'])]
    public function desabonner(Request $request): JsonResponse
    {
        if ($erreur = $this->verifier($request)) {
            return $erreur;
        }
        /** @var User $user */
        $user = $this->getUser();
        $this->push->desabonner($user, (string) ($request->toArray()['endpoint'] ?? ''));

        return $this->json(['ok' => true]);
    }

    #[Route('/test', name: 'push_test', methods: ['POST'])]
    public function test(Request $request): JsonResponse
    {
        if ($erreur = $this->verifier($request)) {
            return $erreur;
        }
        /** @var User $user */
        $user = $this->getUser();
        $n = $this->push->envoyer($user, 'Notifications activées', 'Vous recevrez ici les rappels des ramassages.', $this->generateUrl('ramassage_index', [], 0), 'test');

        return $this->json(['envoyees' => $n]);
    }

    private function verifier(Request $request): ?JsonResponse
    {
        if (!$this->push->estConfiguree()) {
            return $this->json(['error' => 'Notifications non configurées sur le serveur (clés VAPID).'], 503);
        }
        if (!$this->isCsrfTokenValid('push', $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['error' => 'Jeton invalide, rechargez la page.'], 403);
        }

        return null;
    }
}
