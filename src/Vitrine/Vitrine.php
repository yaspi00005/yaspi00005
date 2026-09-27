<?php

namespace App\Vitrine;

use Symfony\Bundle\FrameworkBundle\Routing\Attribute\AsRoutingConditionService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

/**
 * Site vitrine public (sotrama.net) servi par la même application que l'espace de gestion
 * (app.sotrama.net) : le nom de domaine de la requête décide du site affiché.
 */
#[AsRoutingConditionService(alias: 'vitrine')]
final class Vitrine
{
    /** @var list<string> */
    private readonly array $domaines;

    public function __construct(
        #[Autowire('%env(VITRINE_DOMAINES)%')] string $domaines,
        #[Autowire('%env(APP_URL)%')] private readonly string $urlApplication,
    ) {
        $this->domaines = array_values(array_filter(array_map(fn ($d) => strtolower(trim($d)), explode(',', $domaines))));
    }

    /** Condition des routes de la vitrine : « service('vitrine').estVitrine(request) ». */
    public function estVitrine(Request $request): bool
    {
        return \in_array(strtolower($request->getHost()), $this->domaines, true);
    }

    /** Adresse de l'espace de gestion (sans « / » final). */
    public function urlApplication(): string
    {
        return rtrim($this->urlApplication, '/');
    }
}
