<?php

namespace App\Enum;

enum StatutColis: string
{
    case EnPreparation = 'en_preparation';
    case Expedie = 'expedie';
    case EnTransit = 'en_transit';
    /** Conteneur pour Bamako débarqué au port de Dakar, puis acheminé par la route. */
    case ArriveDakar = 'arrive_dakar';
    case TransitBamako = 'transit_bamako';
    case RetenuDouane = 'retenu_douane';
    case Arrive = 'arrive';
    case Recupere = 'recupere';

    public function label(): string
    {
        return match ($this) {
            self::EnPreparation => 'En préparation',
            self::Expedie => 'Expédié',
            self::EnTransit => 'En transit',
            self::ArriveDakar => 'Arrivé à Dakar',
            self::TransitBamako => 'Transit Dakar → Bamako',
            self::RetenuDouane => 'Retenu en douane',
            self::Arrive => 'Arrivé',
            self::Recupere => 'Récupéré',
        };
    }

    /** Classe de badge Bootstrap / Workload. */
    public function badge(): string
    {
        return match ($this) {
            self::EnPreparation => 'warning',
            self::Expedie => 'dark',
            self::EnTransit => 'info',
            self::ArriveDakar, self::TransitBamako => 'info',
            self::RetenuDouane => 'danger',
            self::Arrive => 'primary',
            self::Recupere => 'success',
        };
    }

    public function rang(): int
    {
        return match ($this) {
            self::EnPreparation => 1,
            self::Expedie => 2,
            self::EnTransit => 3,
            self::ArriveDakar => 4,
            self::TransitBamako => 5,
            self::RetenuDouane => 6,
            self::Arrive => 7,
            self::Recupere => 8,
        };
    }
}
