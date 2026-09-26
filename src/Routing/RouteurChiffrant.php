<?php

namespace App\Routing;

use App\Security\IdChiffreur;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\CacheWarmer\WarmableInterface;
use Symfony\Component\Routing\Matcher\RequestMatcherInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

/**
 * Chiffre automatiquement, dans toutes les URL générées (path(), redirectToRoute()…),
 * les paramètres qui portent un identifiant d'entité.
 */
#[AsDecorator('router')]
final class RouteurChiffrant implements RouterInterface, RequestMatcherInterface, WarmableInterface
{
    /** Paramètres de route ou de requête contenant un identifiant. */
    public const PARAMETRES = ['id', 'colis', 'client', 'expedition', 'caissier'];

    public function __construct(
        #[AutowireDecorated] private readonly RouterInterface&RequestMatcherInterface&WarmableInterface $routeur,
        private readonly IdChiffreur $chiffreur,
    ) {
    }

    public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
    {
        foreach (self::PARAMETRES as $cle) {
            $valeur = $parameters[$cle] ?? null;
            if (\is_int($valeur) || (\is_string($valeur) && ctype_digit($valeur))) {
                $parameters[$cle] = $this->chiffreur->encode((int) $valeur);
            }
        }

        return $this->routeur->generate($name, $parameters, $referenceType);
    }

    public function setContext(RequestContext $context): void
    {
        $this->routeur->setContext($context);
    }

    public function getContext(): RequestContext
    {
        return $this->routeur->getContext();
    }

    public function getRouteCollection(): RouteCollection
    {
        return $this->routeur->getRouteCollection();
    }

    public function match(string $pathinfo): array
    {
        return $this->routeur->match($pathinfo);
    }

    public function matchRequest(Request $request): array
    {
        return $this->routeur->matchRequest($request);
    }

    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        return $this->routeur->warmUp($cacheDir, $buildDir);
    }
}
