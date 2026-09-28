<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Nonce (nombre unique) de la politique de sécurité du contenu (CSP), régénéré à chaque requête.
 *
 * Placé sur chaque <script> intégré à une page ET dans l'en-tête Content-Security-Policy
 * (voir EnTetesSecuriteListener) : le navigateur n'exécute alors que les scripts portant ce nonce,
 * ce qui permet de retirer « unsafe-inline » et bloque tout script injecté (XSS).
 */
final class CspNonce
{
    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    public function valeur(): string
    {
        $requete = $this->requestStack->getCurrentRequest();
        if (!$requete) {
            return '';
        }
        if (!$requete->attributes->has('_csp_nonce')) {
            $requete->attributes->set('_csp_nonce', base64_encode(random_bytes(16)));
        }

        return (string) $requete->attributes->get('_csp_nonce');
    }
}
