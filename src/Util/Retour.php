<?php

namespace App\Util;

use Symfony\Component\HttpFoundation\Request;

/**
 * « Retour à la page d'origine » : une liste (paiements filtrés, expédition, guichet…) ouvre la fiche d'un colis
 * avec ?retour=<adresse de la liste>. Après un paiement, la fiche propose d'y revenir, filtres compris.
 */
final class Retour
{
    /** Début d'adresse → libellé du bouton. */
    private const PAGES = [
        '/paiements' => 'Retour aux paiements',
        '/encaissements' => 'Retour à mes encaissements',
        '/expeditions/' => "Retour à l'expédition",
        '/agence' => 'Retour au guichet',
        '/clients/' => 'Retour à la fiche client',
        '/appels' => 'Retour au registre des appels',
        '/colis' => 'Retour à la liste des colis',
    ];

    /** Adresse de retour de la requête (paramètre GET ou champ de formulaire), si elle est sûre. */
    public static function depuis(Request $request): ?string
    {
        return self::valider($request->query->getString('retour') ?: $request->request->getString('retour'));
    }

    /** Seulement une adresse de l'application (« /… ») : jamais un autre site (« //site », « https:// », « /\site »). */
    public static function valider(?string $url): ?string
    {
        if (null === $url || '' === $url || \strlen($url) > 1000 || !str_starts_with($url, '/')
            || str_starts_with($url, '//') || str_starts_with($url, '/\\') || preg_match('/[\x00-\x1F\x7F]/', $url)) {
            return null;
        }

        return $url;
    }

    public static function libelle(string $url): string
    {
        if ('/' === $url || str_starts_with($url, '/?')) {
            return 'Retour au tableau de bord';
        }
        foreach (self::PAGES as $debut => $libelle) {
            if (str_starts_with($url, $debut)) {
                return $libelle;
            }
        }

        return 'Retour à la page précédente';
    }
}
