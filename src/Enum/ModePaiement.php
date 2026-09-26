<?php

namespace App\Enum;

enum ModePaiement: string
{
    case Especes = 'especes';
    case Carte = 'carte';
    case Virement = 'virement';
    case OrangeMoney = 'orange_money';
    case Wave = 'wave';
    case Bonus = 'bonus';

    public function label(): string
    {
        return match ($this) {
            self::Especes => 'Espèces',
            self::Carte => 'Carte bancaire',
            self::Virement => 'Virement',
            self::OrangeMoney => 'Orange Money',
            self::Wave => 'Wave',
            self::Bonus => 'Bonus fidélité',
        };
    }

    /** Modes proposés au caissier (le bonus passe par un flux dédié). */
    public static function encaissables(): array
    {
        return array_values(array_filter(self::cases(), fn (self $m) => self::Bonus !== $m));
    }
}
