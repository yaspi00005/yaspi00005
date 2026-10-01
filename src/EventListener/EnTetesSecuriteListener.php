<?php

namespace App\EventListener;

use App\Security\CspNonce;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * En-têtes de sécurité HTTP sur toutes les réponses :
 *  - CSP : scripts, styles, images et cadres limités au site (+ Google Fonts et la carte OpenStreetMap) ;
 *    aucune page ne peut être affichée dans le cadre d'un autre site (clickjacking) ;
 *  - pas de détection de type par le navigateur (nosniff), référent limité ;
 *  - caméra et position autorisées pour le site seulement, le reste coupé ;
 *  - HSTS en production (HTTPS imposé au navigateur pendant un an).
 */
final class EnTetesSecuriteListener
{
    private const CSP = [
        "default-src 'self'",
        // Scripts du site, et scripts des pages seulement s'ils portent le nonce de la requête (ajouté plus bas).
        "script-src 'self'",
        "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
        "font-src 'self' data: https://fonts.gstatic.com",
        // blob: aperçu des photos avant envoi ; data: QR codes.
        "img-src 'self' data: blob: https://*.tile.openstreetmap.org",
        "connect-src 'self'",
        'frame-src https://www.openstreetmap.org',
        "frame-ancestors 'self'",
        "worker-src 'self'",
        "manifest-src 'self'",
        "object-src 'none'",
        "base-uri 'self'",
        "form-action 'self'",
    ];

    public function __construct(
        #[Autowire('%kernel.environment%')] private readonly string $environnement,
        private readonly CspNonce $nonce,
    ) {
    }

    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -10)]
    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $en = $event->getResponse()->headers;
        $en->set('X-Content-Type-Options', 'nosniff');
        $en->set('X-Frame-Options', 'SAMEORIGIN');
        $en->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $en->set('Permissions-Policy', 'camera=(self), geolocation=(self), microphone=(), payment=(), usb=(), interest-cohort=()');
        $en->set('Cross-Origin-Opener-Policy', 'same-origin');
        if (!$en->has('Content-Security-Policy') && 'dev' !== $this->environnement) {
            // En développement, la barre de débogage de Symfony a besoin de plus de libertés.
            $directives = self::CSP;
            $directives[1] .= " 'nonce-".$this->nonce->get()."'";
            // Pas d'attribut onclick/onchange : interdits, les comportements passent par public/js.
            $directives[] = "script-src-attr 'none'";
            $en->set('Content-Security-Policy', implode('; ', $directives));
        }
        if ('prod' === $this->environnement && $event->getRequest()->isSecure()) {
            $en->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
    }
}
