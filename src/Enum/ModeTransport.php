<?php

namespace App\Enum;

enum ModeTransport: string
{
    case Aerien = 'aerien';
    case Maritime = 'maritime';

    public function label(): string
    {
        return match ($this) {
            self::Aerien => 'Aérien',
            self::Maritime => 'Maritime',
        };
    }

    public function icone(): string
    {
        return match ($this) {
            self::Aerien => 'fa-plane',
            self::Maritime => 'fa-ship',
        };
    }
}
