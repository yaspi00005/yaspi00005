<?php

namespace App\Enum;

enum StatutRamassage: string
{
    case EnAttente = 'en_attente';
    case Confirme = 'confirme';
    case Effectue = 'effectue';
    case Annule = 'annule';

    public function label(): string
    {
        return match ($this) {
            self::EnAttente => 'En attente',
            self::Confirme => 'Confirmé par le client',
            self::Effectue => 'Effectué',
            self::Annule => 'Annulé',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::EnAttente => 'warning',
            self::Confirme => 'info',
            self::Effectue => 'success',
            self::Annule => 'danger',
        };
    }

    /** Ramassage encore à faire (rappels, « À venir », case « Fait » du planning). */
    public function estAFaire(): bool
    {
        return self::EnAttente === $this || self::Confirme === $this;
    }

    /** @return list<self> */
    public static function aFaire(): array
    {
        return [self::EnAttente, self::Confirme];
    }
}
