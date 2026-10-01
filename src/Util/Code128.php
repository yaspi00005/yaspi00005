<?php

namespace App\Util;

/**
 * Code-barres Code 128 (jeu B : lettres, chiffres, tirets) pour les étiquettes des colis : lisible par toutes les
 * douchettes USB, y compris les modèles qui ne lisent pas les QR codes.
 */
final class Code128
{
    /** Motifs des 107 symboles : largeurs alternées barre/espace (en modules). */
    private const MOTIFS = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
    ];
    private const DEPART_B = 104;
    private const ARRET = 106;

    /** @return list<int> largeurs alternées barre/espace, zones blanches comprises */
    public static function modules(string $texte): array
    {
        $texte = preg_replace('/[^\x20-\x7E]/', '', $texte) ?? '';
        $codes = [self::DEPART_B];
        $somme = self::DEPART_B;
        foreach (str_split($texte) as $i => $car) {
            $valeur = \ord($car) - 32;
            $codes[] = $valeur;
            $somme += $valeur * ($i + 1);
        }
        $codes[] = $somme % 103;
        $codes[] = self::ARRET;

        $largeurs = [10]; // zone blanche de départ (espace)
        foreach ($codes as $code) {
            foreach (str_split(self::MOTIFS[$code]) as $l) {
                $largeurs[] = (int) $l;
            }
        }
        $largeurs[] = 10; // zone blanche de fin

        return $largeurs;
    }

    /** Image PNG (data URI) noir et blanc, nette à l'impression. */
    public static function pngDataUri(string $texte, int $module = 3, int $hauteur = 120): string
    {
        $largeurs = self::modules($texte);
        $image = imagecreate(array_sum($largeurs) * $module, $hauteur);
        $blanc = imagecolorallocate($image, 255, 255, 255);
        $noir = imagecolorallocate($image, 0, 0, 0);
        imagefill($image, 0, 0, $blanc);
        $x = 0;
        // Commence par un espace (zone blanche), puis barre, espace, barre…
        foreach ($largeurs as $i => $l) {
            if (1 === $i % 2) {
                imagefilledrectangle($image, $x, 0, $x + $l * $module - 1, $hauteur - 1, $noir);
            }
            $x += $l * $module;
        }
        ob_start();
        imagepng($image);
        imagedestroy($image);

        return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
    }
}
