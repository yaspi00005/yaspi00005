<?php

namespace App\Enum;

enum StatutRamassage: string
{
    case EnAttente = 'en_attente';
    case Effectue = 'effectue';
    case Annule = 'annule';

    public function label(): string
    {
        return match ($this) {
            self::EnAttente => 'En attente',
            self::Effectue => 'Effectué',
            self::Annule => 'Annulé',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::EnAttente => 'warning',
            self::Effectue => 'success',
            self::Annule => 'danger',
        };
    }
}
