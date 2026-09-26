<?php

namespace App\Enum;

enum StatutPaiement: string
{
    case NonPaye = 'non_paye';
    case Partiel = 'partiel';
    case Paye = 'paye';

    public function label(): string
    {
        return match ($this) {
            self::NonPaye => 'Non payé',
            self::Partiel => 'Partiellement payé',
            self::Paye => 'Payé',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::NonPaye => 'danger',
            self::Partiel => 'warning',
            self::Paye => 'success',
        };
    }
}
