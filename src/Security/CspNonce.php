<?php

namespace App\Security;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Code aléatoire (nonce) propre à chaque page : seuls les scripts écrits dans nos gabarits le portent
 * (<script nonce="{{ csp_nonce() }}">) et la CSP n'autorise qu'eux. Un script injecté ne s'exécute pas.
 */
final class CspNonce implements ResetInterface
{
    private ?string $nonce = null;

    public function get(): string
    {
        return $this->nonce ??= rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
    }

    /** Nouveau code à chaque requête. */
    public function reset(): void
    {
        $this->nonce = null;
    }
}
