<?php

namespace App\Enum;

enum StatutExpedition: string
{
    case EnPreparation = 'en_preparation';
    case Expediee = 'expediee';
    case EnTransit = 'en_transit';
    case RetenueDouane = 'retenue_douane';
    case Arrivee = 'arrivee';
    case Cloturee = 'cloturee';

    public function label(): string
    {
        return match ($this) {
            self::EnPreparation => 'En préparation',
            self::Expediee => 'Expédiée',
            self::EnTransit => 'En transit',
            self::RetenueDouane => 'Retenue en douane',
            self::Arrivee => 'Arrivée',
            self::Cloturee => 'Clôturée',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::EnPreparation => 'warning',
            self::Expediee => 'dark',
            self::EnTransit => 'info',
            self::RetenueDouane => 'danger',
            self::Arrivee => 'primary',
            self::Cloturee => 'success',
        };
    }

    /** Statut à appliquer aux colis de l'expédition lorsque celle-ci change de statut. */
    public function statutColis(): ?StatutColis
    {
        return match ($this) {
            self::EnPreparation => StatutColis::EnPreparation,
            self::Expediee => StatutColis::Expedie,
            self::EnTransit => StatutColis::EnTransit,
            self::RetenueDouane => StatutColis::RetenuDouane,
            self::Arrivee => StatutColis::Arrive,
            self::Cloturee => null,
        };
    }

    public function accepteNouveauxColis(): bool
    {
        return self::EnPreparation === $this;
    }
}
