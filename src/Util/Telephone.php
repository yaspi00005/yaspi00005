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
}
