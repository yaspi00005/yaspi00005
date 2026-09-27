<?php

namespace App\Vitrine;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Sur le domaine de la vitrine, les pages de l'espace de gestion (/connexion, /colis…)
 * sont renvoyées vers app.sotrama.net : la vitrine ne montre que ses propres pages.
 */
final class RedirectionApplicationListener
{
    public function __construct(private readonly Vitrine $vitrine)
    {
    }

    // Après le routeur (32), avant le pare-feu (8).
    #[AsEventListener(event: KernelEvents::REQUEST, priority: 30)]
    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$this->vitrine->estVitrine($request)) {
            return;
        }
        $route = (string) $request->attributes->get('_route');
        if ('' === $route || str_starts_with($route, 'vitrine_') || str_starts_with($route, '_')) {
            return;
        }
        $query = $request->getQueryString();
        $event->setResponse(new RedirectResponse($this->vitrine->urlApplication().$request->getPathInfo().($query ? '?'.$query : '')));
    }
}
