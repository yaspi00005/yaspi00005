<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Tant que l'utilisateur n'a pas changé le mot de passe provisoire (Sotrama2025) ni renseigné son adresse
 * e-mail, toutes les pages le renvoient vers « Mon profil ».
 */
final class ProfilObligatoireListener
{
    /** Pages toujours accessibles : le profil lui-même, la déconnexion, l'aide, les outils de développement. */
    private const ROUTES_LIBRES = ['app_profil', 'app_logout', 'app_login', 'app_aide', 'app_application', 'app_apk', 'app_assetlinks'];

    public function __construct(
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /** Après le pare-feu (priorité 8), pour connaître l'utilisateur. */
    #[AsEventListener(event: KernelEvents::REQUEST, priority: 0)]
    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $user = $this->security->getUser();
        $route = (string) $event->getRequest()->attributes->get('_route');
        if (!$user instanceof User || !$user->isProfilAComplete()
            || \in_array($route, self::ROUTES_LIBRES, true) || str_starts_with($route, '_') || str_starts_with($route, 'vitrine_')) {
            return;
        }

        $requete = $event->getRequest();
        if ($requete->isXmlHttpRequest() || 'json' === $requete->getPreferredFormat()) {
            $event->setResponse(new JsonResponse(['erreur' => 'Complétez d\'abord votre profil (mot de passe, e-mail).'], 403));

            return;
        }
        $event->setResponse(new RedirectResponse($this->urls->generate('app_profil')));
    }
}
