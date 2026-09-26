<?php

namespace App\Enum;

enum StatutColis: string
{
    case EnPreparation = 'en_preparation';
    case Expedie = 'expedie';
    case EnTransit = 'en_transit';
    case RetenuDouane = 'retenu_douane';
    case Arrive = 'arrive';
    case Recupere = 'recupere';

    public function label(): string
    {
        return match ($this) {
            self::EnPreparation => 'En préparation',
            self::Expedie => 'Expédié',
            self::EnTransit => 'En transit',
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
            self::RetenuDouane => 4,
            self::Arrive => 5,
            self::Recupere => 6,
        };
    }
}
