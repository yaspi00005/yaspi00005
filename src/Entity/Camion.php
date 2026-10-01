<?php

namespace App\Entity;

use App\Repository\CamionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/** Véhicule des ramassages (Sprinter, Boxer, 20 m³…) avec son chauffeur habituel. */
#[ORM\Entity(repositoryClass: CamionRepository::class)]
class Camion
{
    /** Couleurs proposées pour distinguer les camions dans le planning. */
    public const COULEURS = ['Vert' => '#00A15D', 'Bleu' => '#3A7BD5', 'Orange' => '#F2994A', 'Violet' => '#8E44AD', 'Rouge' => '#D7263D', 'Turquoise' => '#16A3B8', 'Marron' => '#8D6E63', 'Gris' => '#5F6B73'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 60)]
    #[Assert\NotBlank(message: 'Indiquez le nom du camion.')]
    #[Assert\Length(max: 60)]
    private ?string $nom = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Assert\Length(max: 20)]
    private ?string $immatriculation = null;

    #[ORM\Column(length: 30, nullable: true)]
    #[Assert\Length(max: 30)]
    private ?string $volume = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $chauffeur = null;

    #[ORM\Column(length: 7)]
    #[Assert\Choice(choices: self::COULEURS)]
    private string $couleur = '#00A15D';

    #[ORM\Column(options: ['default' => true])]
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
        $this->nom = null === $nom ? null : trim($nom);

        return $this;
    }

    public function getImmatriculation(): ?string
    {
        return $this->immatriculation;
    }

    public function setImmatriculation(?string $immatriculation): static
    {
        $this->immatriculation = $immatriculation ? mb_strtoupper(trim($immatriculation)) : null;

        return $this;
    }

    public function getVolume(): ?string
    {
        return $this->volume;
    }

    public function setVolume(?string $volume): static
    {
        $this->volume = $volume;

        return $this;
    }

    public function getChauffeur(): ?User
    {
        return $this->chauffeur;
    }

    public function setChauffeur(?User $chauffeur): static
    {
        $this->chauffeur = $chauffeur;

        return $this;
    }

    public function getCouleur(): string
    {
        return $this->couleur;
    }

    public function setCouleur(?string $couleur): static
    {
        $this->couleur = $couleur ?: '#00A15D';

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
