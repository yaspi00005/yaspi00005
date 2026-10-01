<?php

namespace App\Enum;

enum StatutRamassage: string
{
    /** Demandée par un visiteur sur sotrama.net : l'équipe doit la valider (pas de rappels avant). */
    case Demande = 'demande';
    case EnAttente = 'en_attente';
    case Confirme = 'confirme';
    case Effectue = 'effectue';
    case Annule = 'annule';

    public function label(): string
    {
        return match ($this) {
            self::Demande => 'Demande en ligne',
            self::EnAttente => 'En attente',
            self::Confirme => 'Confirmé par le client',
            self::Effectue => 'Effectué',
            self::Annule => 'Annulé',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Demande => 'primary',
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
