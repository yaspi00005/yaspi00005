<?php

namespace App\Enum;

enum TypeExpedition: string
{
    case Standard = 'standard';
    case Express = 'express';

    public function label(): string
    {
        return match ($this) {
            self::Standard => 'Standard',
            self::Express => 'Express',
        };
    }
}
