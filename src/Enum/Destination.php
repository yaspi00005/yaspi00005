<?php

namespace App\Enum;

enum Destination: string
{
    case Mali = 'mali';
    case Senegal = 'senegal';

    public function label(): string
    {
        return match ($this) {
            self::Mali => 'Mali',
            self::Senegal => 'Sénégal',
        };
    }

    public function ville(): string
    {
        return match ($this) {
            self::Mali => 'Bamako',
            self::Senegal => 'Dakar',
        };
    }

    public function indicatif(): string
    {
        return match ($this) {
            self::Mali => '+223',
            self::Senegal => '+221',
        };
    }
}
