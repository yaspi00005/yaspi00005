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
                fputcsv($out, $ligne, ';', '"', '');
            }
            fclose($out);
        });
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="'.$fichier.'"');

        return $response;
    }

    public static function montant(int $centimes): string
    {
        return number_format($centimes / 100, 2, ',', '');
    }
}
