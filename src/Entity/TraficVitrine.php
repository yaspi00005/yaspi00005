<?php

namespace App\Entity;

use App\Repository\TraficVitrineRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Compteur journalier anonyme du site vitrine (aucune adresse IP ni cookie) : visiteurs, pages vues,
 * provenance, type d'appareil et actions (demandes de ramassage, colis suivis). Voir App\Vitrine\CompteurTrafic.
 */
#[ORM\Entity(repositoryClass: TraficVitrineRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_TRAFIC_JOUR_TYPE_CLE', columns: ['jour', 'type', 'cle'])]
class TraficVitrine
{
    public const VISITEURS = 'visiteurs';
    public const PAGE = 'page';
    public const SOURCE = 'source';
    public const APPAREIL = 'appareil';
    public const ACTION = 'action';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $jour;

    #[ORM\Column(length: 20)]
    private string $type;

    #[ORM\Column(length: 60)]
    private string $cle;

    #[ORM\Column]
    private int $nombre = 0;

    public function __construct(\DateTimeImmutable $jour, string $type, string $cle, int $nombre = 0)
    {
        $this->jour = $jour;
        $this->type = $type;
        $this->cle = $cle;
        $this->nombre = $nombre;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getJour(): \DateTimeImmutable
    {
        return $this->jour;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getCle(): string
    {
        return $this->cle;
    }

    public function getNombre(): int
    {
        return $this->nombre;
    }
}
