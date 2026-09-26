<?php

namespace App\Routing;

use App\Security\IdChiffreur;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Déchiffre les identifiants de route (juste après le routage, avant le chargement des entités).
 * Un identifiant invalide ou falsifié donne une erreur 404.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 31)]
final class DechiffrementIdListener
{
    public function __construct(private readonly IdChiffreur $chiffreur)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        $attributs = $event->getRequest()->attributes;
        $parametresRoute = $attributs->get('_route_params', []);
        foreach (RouteurChiffrant::PARAMETRES as $cle) {
            if (!\array_key_exists($cle, $parametresRoute)) {
                continue;
            }
            $id = $this->chiffreur->decode((string) $parametresRoute[$cle]);
            if (null === $id) {
                throw new NotFoundHttpException('Lien invalide.');
            }
            $attributs->set($cle, $id);
            $parametresRoute[$cle] = $id;
        }
        $attributs->set('_route_params', $parametresRoute);
    }
}
