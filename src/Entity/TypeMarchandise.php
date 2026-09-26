<?php

namespace App\Entity;

use App\Repository\TypeMarchandiseRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: TypeMarchandiseRepository::class)]
#[UniqueEntity('nom')]
class TypeMarchandise
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100, unique: true)]
    #[Assert\NotBlank(message: "Indiquez le nom du type de marchandise.")]
    private ?string $nom = null;

    /** Tarif indicatif au kg en centimes, pré-rempli lors de la saisie d'un article. */
    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero]
    private ?int $prixUnitaire = null;

    #[ORM\Column]
    private bool $actif = true;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(?string $nom): static
    {
        $this->nom = $nom;

        return $this;
    }

    public function getPrixUnitaire(): ?int
    {
        return $this->prixUnitaire;
    }

    public function setPrixUnitaire(?int $prixUnitaire): static
    {
        $this->prixUnitaire = $prixUnitaire;

        return $this;
    }

    public function isActif(): bool
    {
        return $this->actif;
    }

    public function setActif(bool $actif): static
    {
        $this->actif = $actif;

        return $this;
    }

    public function __toString(): string
    {
        return (string) $this->nom;
    }
}
