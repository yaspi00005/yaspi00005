<?php

namespace App\Util;

/**
 * Dates en français sans l'extension PHP « intl » (absente de certains PHP, dont XAMPP sur Mac).
 */
final class DateFr
{
    public const MOIS = [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    private const MOIS_COURTS = [1 => 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
    private const JOURS = [1 => 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];

    /** « sept. 26 » (graphiques mensuels). */
    public static function moisCourt(\DateTimeInterface $d): string
    {
        return self::MOIS_COURTS[(int) $d->format('n')].' '.$d->format('y');
    }

    /** « samedi 27 septembre 2026 ». */
    public static function jour(\DateTimeInterface $d, bool $annee = true): string
    {
        return self::JOURS[(int) $d->format('N')].' '.$d->format('j').' '.self::MOIS[(int) $d->format('n')].($annee ? ' '.$d->format('Y') : '');
    }

    /** « samedi 27 septembre à 14:30 ». */
    public static function jourHeure(\DateTimeInterface $d): string
    {
        return self::jour($d, false).' à '.$d->format('H:i');
    }
}
