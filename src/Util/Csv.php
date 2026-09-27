<?php

namespace App\Util;

use Symfony\Component\HttpFoundation\StreamedResponse;

final class Csv
{
    /**
     * Réponse CSV compatible Excel (UTF-8 avec BOM, séparateur « ; »).
     *
     * @param list<string>          $entetes
     * @param iterable<list<mixed>> $lignes
     */
    public static function reponse(string $fichier, array $entetes, iterable $lignes): StreamedResponse
    {
        $response = new StreamedResponse(function () use ($entetes, $lignes) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $entetes, ';', '"', '');
            foreach ($lignes as $ligne) {
                fputcsv($out, array_map(self::cellule(...), $ligne), ';', '"', '');
            }
            fclose($out);
        });
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="'.$fichier.'"');

        return $response;
    }

    /**
     * Neutralise les formules : un texte saisi par un client ou un agent qui commence par = + - @
     * (ex. « =HYPERLINK(...) » dans un nom) serait exécuté par Excel à l'ouverture du fichier.
     */
    public static function cellule(mixed $valeur): mixed
    {
        if (\is_string($valeur) && '' !== $valeur && \in_array($valeur[0], ['=', '+', '-', '@', "\t", "\r"], true) && !is_numeric(str_replace(",", ".", $valeur))) {
            return "'".$valeur;
        }

        return $valeur;
    }

    public static function montant(int $centimes): string
    {
        return number_format($centimes / 100, 2, ',', '');
    }
}
