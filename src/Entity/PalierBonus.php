<?php

namespace App\Entity;

use App\Repository\PalierBonusRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Palier du barème du bonus fidélité, modifiable par l'administrateur : un colis dont le total
 * des articles atteint le seuil rapporte ce bonus au client (montants en centimes).
 */
#[ORM\Entity(repositoryClass: PalierBonusRepository::class)]
#[ORM\UniqueConstraint(columns: ['seuil'])]
class PalierBonus
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\Column]
        private int $seuil = 0,
        #[ORM\Column]
        private int $montant = 0,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSeuil(): int
    {
        return $this->seuil;
    }

    public function setSeuil(int $seuil): static
    {
        $this->seuil = $seuil;

        return $this;
    }

    public function getMontant(): int
    {
        return $this->montant;
    }

    public function setMontant(int $montant): static
    {
        $this->montant = $montant;

        return $this;
    }

    public function __toString(): string
    {
        return \sprintf('Palier bonus : dès %s € → %s €', number_format($this->seuil / 100, 2, ',', ' '), number_format($this->montant / 100, 2, ',', ' '));
    }
}
