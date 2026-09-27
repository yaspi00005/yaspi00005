<?php

namespace App\Util;

use App\Enum\Destination;

/** Numéros de téléphone au format international sans « + » (liens wa.me). */
final class Telephone
{
    /**
     * « 06 12 34 56 78 » → 33612345678 (France), « 76 11 22 33 » → 22376112233 (Mali),
     * « 77 123 45 67 » → 221771234567 (Sénégal), « +223… » / « 00223… » conservés.
     */
    public static function international(?string $numero, ?Destination $pays = null): ?string
    {
        $numero = trim((string) $numero);
        $chiffres = preg_replace('/\D/', '', $numero);
        if ('' === $chiffres || \strlen($chiffres) < 8) {
            return null;
        }
        if (str_starts_with($numero, '+')) {
            return $chiffres;
        }
        if (str_starts_with($chiffres, '00')) {
            return substr($chiffres, 2);
        }

        return match (true) {
            10 === \strlen($chiffres) && '0' === $chiffres[0] => '33'.substr($chiffres, 1),   // France
            8 === \strlen($chiffres) => '223'.$chiffres,                                       // Mali
            9 === \strlen($chiffres) && '7' === $chiffres[0] => '221'.$chiffres,               // Sénégal
            null !== $pays => ltrim($pays->indicatif(), '+').ltrim($chiffres, '0'),
            default => $chiffres,
        };
    }

    /**
     * Affichage lisible : « 33758801507 » → « 07 58 80 15 07 », « 22366547781 » → « +223 66 54 77 81 »,
     * « 221771234567 » → « +221 77 123 45 67 » ; sinon groupes de deux chiffres.
     */
    public static function affichage(?string $numero): string
    {
        $numero = trim((string) $numero);
        $chiffres = preg_replace('/\D/', '', $numero);
        if (str_starts_with($chiffres, '00')) {
            $chiffres = substr($chiffres, 2);
        }
        $paires = fn (string $c) => trim(chunk_split($c, 2, ' '));

        return match (true) {
            '' === $chiffres => $numero,
            11 === \strlen($chiffres) && str_starts_with($chiffres, '33') => $paires('0'.substr($chiffres, 2)),
            11 === \strlen($chiffres) && str_starts_with($chiffres, '223') => '+223 '.$paires(substr($chiffres, 3)),
            12 === \strlen($chiffres) && str_starts_with($chiffres, '221') => '+221 '.substr($chiffres, 3, 2).' '.substr($chiffres, 5, 3).' '.substr($chiffres, 8, 2).' '.substr($chiffres, 10),
            9 === \strlen($chiffres) && '7' === $chiffres[0] => substr($chiffres, 0, 2).' '.substr($chiffres, 2, 3).' '.substr($chiffres, 5, 2).' '.substr($chiffres, 7),
            default => $paires($chiffres),
        };
    }
}
