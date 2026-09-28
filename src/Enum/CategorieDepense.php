<?php

namespace App\Enum;

/** Nature des charges d'une expédition. */
enum CategorieDepense: string
{
    case Fret = 'fret';
    case Transport = 'transport';
    case Douane = 'douane';
    case Manutention = 'manutention';
    case Emballage = 'emballage';
    case Commission = 'commission';
    case Autre = 'autre';

    public function label(): string
    {
        return match ($this) {
            self::Fret => 'Fret (conteneur, billet avion, GP)',
            self::Transport => 'Transport routier',
            self::Douane => 'Douane et taxes',
            self::Manutention => 'Chargement / déchargement',
            self::Emballage => 'Emballage et matériel',
            self::Commission => 'Commissions et frais d\'agence',
            self::Autre => 'Autre dépense',
        };
    }

    public function icone(): string
    {
        return match ($this) {
            self::Fret => 'fa-ship',
            self::Transport => 'fa-truck',
            self::Douane => 'fa-landmark',
            self::Manutention => 'fa-people-carry',
            self::Emballage => 'fa-box',
            self::Commission => 'fa-handshake',
            self::Autre => 'fa-receipt',
        };
    }
}
